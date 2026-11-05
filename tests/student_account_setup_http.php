<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
$source = $pdo;
$root = dirname(__DIR__);
$database = 'kns_setup_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/kns-setup-' . bin2hex(random_bytes(8));
$web = $temporary . '/web';
$app = $web . '/kns_portal';
$server = null;
$created = false;
$checks = 0;
function httpCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function copySetupTree(string $from, string $to): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to, 0700, true);
        foreach (new DirectoryIterator($from) as $entry) {
            if (!$entry->isDot()) copySetupTree($entry->getPathname(), $to . '/' . $entry->getFilename());
        }
    } else { copy($from, $to); }
}
function removeSetupTree(string $path, string $allowed): void
{
    $resolved = realpath($path);
    if ($resolved === false) return;
    $prefix = rtrim(str_replace('\\', '/', $allowed), '/') . '/';
    $normal = str_replace('\\', '/', $resolved);
    if ($normal !== rtrim($prefix, '/') && !str_starts_with($normal, $prefix)) throw new RuntimeException('Unsafe test cleanup path');
    if (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) removeSetupTree($entry->getPathname(), $allowed);
        rmdir($path);
    } else { unlink($path); }
}
final class SetupBrowser
{
    private array $cookies = [];
    public string $csrf = '';
    public function __construct(private string $base) {}
    public function request(string $path, ?array $fields = null, string $accept = ''): array
    {
        $headers = [];
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15, CURLOPT_COOKIE => implode('; ', $this->cookies),
            CURLOPT_HTTPHEADER => $accept === '' ? [] : ['Accept: ' . $accept],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers): int {
                $headers[] = $line;
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $match)) $this->cookies[$match[1]] = $match[1] . '=' . $match[2];
                return strlen($line);
            }]);
        if ($fields !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        curl_close($curl);
        return ['status' => $status, 'body' => $body, 'location' => $location];
    }
    public function token(string $page = '/login.php'): array
    {
        $response = $this->request($page);
        preg_match('/name="csrf"\s+value="([a-f0-9]+)"/', (string) $response['body'], $match);
        httpCheck(isset($match[1]), 'CSRF token missing from ' . $page);
        $this->csrf = $match[1];
        return $response;
    }
    public function login(string $number, string $password): array
    {
        $this->token();
        return $this->request('/php/auth/login.php', ['csrf' => $this->csrf, 'student_number' => $number, 'password' => $password]);
    }
}
try {
    mkdir($app, 0700, true);
    mkdir($temporary . '/sessions');
    $source->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['students', 'admin_account', 'password_reset_tokens', 'password_reset_rate_limits'] as $table) {
        $pdo->exec($source->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo->exec(file_get_contents($root . '/database/migrations/20261105_student_account_setup.sql'));
    $pdo->exec(file_get_contents($root . '/database/migrations/20261105_student_account_setup.sql'));
    $pdo->exec(file_get_contents($root . '/database/migrations/20261105_academic_term_management.sql'));
    $pdo->exec(file_get_contents($root . '/database/migrations/20261105_student_term_activation.sql'));
    $pdo->exec(file_get_contents($root . '/database/migrations/20261106_student_term_grace.sql'));
    $fixtureToday = new DateTimeImmutable('today', knsAcademicTermZone());
    $fixtureYear = $fixtureToday->format('Y') . '-' . ((int) $fixtureToday->format('Y') + 1);
    (new KnsAcademicTerms($pdo))->schedule(['academic_year'=>$fixtureYear,'semester'=>'1st Semester',
        'start_date'=>$fixtureToday->modify('-1 day')->format('Y-m-d'),
        'official_end_date'=>$fixtureToday->modify('+365 days')->format('Y-m-d')], 1);
    foreach (['php', 'admin', 'dashboard', 'module', 'request', 'config'] as $dir) copySetupTree($root . '/' . $dir, $app . '/' . $dir);
    foreach (['login.php', 'change-password.php', 'test_student_login.php'] as $file) copy($root . '/' . $file, $app . '/' . $file);
    mkdir($app . '/vendor');
    file_put_contents($app . '/vendor/autoload.php', '<?php return require ' . var_export($root . '/vendor/autoload.php', true) . ';');
    file_put_contents($app . '/db.php', '<?php $pdo = new PDO(' . var_export($dsn, true) . ',' . var_export($username, true) . ',' . var_export($password, true) . ',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);');
    file_put_contents($temporary . '/provider.php', '<?php return [];');
    // Equivalent routing for PHP's development server, which does not read .htaccess.
    file_put_contents($temporary . '/router.php', <<<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/kns_portal/(?:module/(?:module-results|request)|request/(?:request|result-request|request-status))\.html$~', $path)) {
    $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/kns_portal/php/auth/student-page.php';
    $_SERVER['SCRIPT_NAME'] = '/kns_portal/php/auth/student-page.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}
return false;
ROUTER);
    $environment = getenv();
    foreach (['KNS_SMTP_USERNAME', 'KNS_SMTP_PASSWORD', 'KNS_SMTP_FROM_EMAIL', 'KNS_TWILIO_ACCOUNT_SID', 'KNS_TWILIO_AUTH_TOKEN', 'KNS_TWILIO_FROM_NUMBER'] as $name) $environment[$name] = '';
    $environment['KNS_PASSWORD_RESET_CONFIG'] = $temporary . '/provider.php';
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $web, $temporary . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $root, $environment, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Could not start isolated HTTP server');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    $base = 'http://' . $address . '/kns_portal';
    $delivered = '';
    $setup = new KnsStudentAccountSetup($pdo, function ($channel, $to, $subject, $body) use (&$delivered): void {
        preg_match('/Temporary password: ([A-Za-z2-9]{8})(?=[.\s]|$)/', $body, $match); $delivered = $match[1];
    });
    $values = ['student_number' => 'HTTP-STUDENT', 'first_name' => 'Sample', 'last_name' => 'Student',
        'course' => 'BSCS', 'year_level' => 1, 'section' => 'TEST', 'email' => 'http@example.invalid'];
    $values += ['academic_year'=>$fixtureYear,'semester'=>'1st Semester','status'=>'Active'];
    $setup->saveImportedStudent($values);
    $initial = $delivered;
    $id = (int) $pdo->query("SELECT id FROM students WHERE student_number='HTTP-STUDENT'")->fetchColumn();
    $localTest = new SetupBrowser($base);
    $localTest->token('/test_student_login.php');
    $localTest->request('/test_student_login.php',['csrf'=>$localTest->csrf,'action'=>'login','student_number'=>'HTTP-STUDENT','password'=>$initial]);
    $response=$localTest->request('/test_student_login.php');
    httpCheck(str_contains($response['body'],'Use the portal login to finish account setup') && !str_contains($response['body'],'Login successful.'),'Local test helper bypassed pending setup');
    $student = new SetupBrowser($base);
    httpCheck(str_ends_with($student->login('HTTP-STUDENT', $initial)['location'], '/change-password.php'), 'Temporary login did not enter setup');
    $student->token('/change-password.php');
    foreach (['/dashboard/dashboard.php', '/module/studentInformation.php', '/module/overview.php', '/module/module-results.html', '/module/request.html', '/request/request.html', '/request/result-request.html', '/request/request-status.html'] as $path) {
        $response = $student->request($path);
        httpCheck($response['status'] === 303 && str_ends_with($response['location'], '/change-password.php'), 'Pending student accessed ' . $path);
    }
    foreach (['/php/module/get-results.php', '/php/student/notifications.php', '/php/student/upload_profile_photo.php', '/php/request/records.php', '/php/request/messages.php', '/php/request/attachment.php', '/php/request/create-request.php'] as $path) {
        $response = $student->request($path, null, 'application/json');
        $data = json_decode($response['body'], true);
        httpCheck($response['status'] === 403 && ($data['redirect'] ?? '') === '/kns_portal/change-password.php', 'Pending student accessed API ' . $path);
    }
    $fields = ['csrf' => 'bad', 'password' => 'Fixture-final-password', 'password_confirmation' => 'Fixture-final-password'];
    httpCheck($student->request('/change-password.php', $fields)['status'] === 403, 'Setup accepted invalid CSRF');
    $fields['csrf'] = $student->csrf; $fields['password'] = $fields['password_confirmation'] = 'short';
    httpCheck($student->request('/change-password.php', $fields)['status'] === 422, 'Setup accepted weak password');
    $fields['password'] = $fields['password_confirmation'] = $initial;
    httpCheck($student->request('/change-password.php', $fields)['status'] === 422, 'Setup allowed same temporary password');
    $fields['password'] = $fields['password_confirmation'] = 'Fixture-final-password';
    httpCheck(str_ends_with($student->request('/change-password.php', $fields)['location'], '/dashboard/dashboard.php'), 'Setup did not complete');
    httpCheck($student->request('/dashboard/dashboard.php')['status'] === 200, 'Completed student cannot access dashboard');
    httpCheck($student->request('/module/module-results.html')['status'] === 200, 'Completed student cannot access HTML fragment');
    $row = $pdo->query('SELECT * FROM students WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
    httpCheck(!(int) $row['requires_password_change'] && $row['temporary_password_expires_at'] === null, 'HTTP setup did not clear flags');
    $other = new SetupBrowser($base);
    httpCheck(str_ends_with($other->login('HTTP-STUDENT', $initial)['location'], '/login.php'), 'Replaced temporary password still logs in');
    httpCheck(str_ends_with($other->login('HTTP-STUDENT', 'Fixture-final-password')['location'], '/dashboard/dashboard.php'), 'Permanent login still restricted');
    $setup->saveImportedStudent(array_replace($values, ['student_number' => 'HTTP-PENDING']));
    $pendingPassword = $delivered;
    $pendingId = (int) $pdo->query("SELECT id FROM students WHERE student_number='HTTP-PENDING'")->fetchColumn();
    $pending = new SetupBrowser($base); $pending->login('HTTP-PENDING', $pendingPassword);
    $setup->resend($pendingId);
    httpCheck(str_ends_with($pending->request('/dashboard/dashboard.php')['location'], '/login.php'), 'Resend failed to revoke old setup session');
    $pdo->exec('UPDATE students SET temporary_password_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=' . $pendingId);
    $expired = new SetupBrowser($base);
    httpCheck(str_ends_with($expired->login('HTTP-PENDING', $delivered)['location'], '/login.php'), 'Expired temporary login accepted');
    httpCheck(str_contains($expired->request('/login.php')['body'], 'Your temporary password has expired'), 'Expired login omitted Forgot Password guidance');
    $adminInsert = $pdo->prepare('INSERT INTO admin_account (username,password_hash,admin_name,role,school_name,school_email,school_contact) VALUES (?,?,?,?,?,?,?)');
    $adminInsert->execute(['fixture.admin',password_hash('Fixture-admin-password',PASSWORD_DEFAULT),'Test Admin','Administrator','Test School','admin@example.invalid','09171234567']);
    $adminInsert->execute(['fixture.registrar',password_hash('Fixture-office-password',PASSWORD_DEFAULT),'Test Registrar','Registrar','Test School','registrar@example.invalid','09171234567']);
    $admin = new SetupBrowser($base); $admin->token();
    $admin->request('/php/auth/admin_login.php', ['csrf'=>$admin->csrf,'student_number'=>'fixture.admin','password'=>'Fixture-admin-password']);
    $admin->token('/admin/results.php');
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet(); $sheet->setTitle('Student Upload');
    $sheet->fromArray([
        ['student_number','Password','first_name','last_name','course','year_level','section','status','email','contact_number','profile_photo'],
        ['HTTP-STUDENT','=1/0','Changed','Student','BSCS',1,'UPDATED','Active','http@example.invalid',null,'bad.png'],
        ['IMPORT-EMAIL','ignored','Example','Email','BSCS',1,'TEST','Active','email@example.invalid'],
        ['IMPORT-BAD','ignored','No','Contact','BSCS',1,'TEST','Active',null,'bad-phone'],
        ['IMPORT-INACTIVE','ignored','Inactive','Student','BSCS',1,'TEST','Inactive'],
        ['IMPORT-PHONE','ignored','Phone','Student','BSCS',1,'TEST','Active',null,'09171234567'],
    ]);
    $sheet->setCellValue('L1','academic_year');$sheet->setCellValue('M1','semester');
    for($fixtureRow=2;$fixtureRow<=6;$fixtureRow++){
        $sheet->setCellValue('L'.$fixtureRow,$fixtureYear);$sheet->setCellValue('M'.$fixtureRow,'1st Semester');
    }
    $workbook = $temporary . '/import.xlsx';
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response = $admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','students.xlsx')]);
    httpCheck($response['status']===200 && str_contains($response['body'],'(3 new, 1 updated)'), 'Mixed-row import counts wrong');
    httpCheck(str_contains($response['body'],'delivery failed') && str_contains($response['body'],'valid email or phone'), 'Import did not report validation/delivery errors');
    httpCheck((int)$pdo->query("SELECT COUNT(*) FROM students WHERE student_number='IMPORT-BAD'")->fetchColumn()===0,'Undeliverable row created');
    $after=$pdo->query('SELECT * FROM students WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    httpCheck($after['password_hash']===$row['password_hash'] && $after['section']==='UPDATED' && $after['profile_photo']===null,'Legacy workbook replaced password/photo');
    httpCheck(!str_contains($response['body'],'ignored') && !str_contains($response['body'],$initial),'Import response leaked password');
    // The current workbook needs no Password header at all.
    $sheet->removeColumn('B');
    $sheet->fromArray([['IMPORT-NO-PASSWORD','No','Password','BSCS',1,'TEST','Active','new@example.invalid']],null,'A7');
    $sheet->setCellValue('K7',$fixtureYear);$sheet->setCellValue('L7','1st Semester');
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response=$admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','students.xlsx')]);
    httpCheck((int)$pdo->query("SELECT COUNT(*) FROM students WHERE student_number='IMPORT-NO-PASSWORD' AND requires_password_change=1")->fetchColumn()===1,'Import without Password column failed');
    $setup->resend($pendingId);
    $pending->login('HTTP-PENDING',$delivered);
    $otp='';
    $recovery=new KnsPasswordResetService($pdo,function($channel,$to,$code)use(&$otp):void{$otp=$code;});
    $session=[];
    $recovery->lookup('HTTP-PENDING',$session,'http-reset-test');
    $recovery->request('HTTP-PENDING','email',$session,'http-reset-test');
    $recovery->verify($otp,$session,'http-reset-test');
    $recovery->reset('Fixture-recovery-password','Fixture-recovery-password',$session,'http-reset-test');
    httpCheck(str_ends_with($pending->request('/dashboard/dashboard.php')['location'],'/login.php'),'Recovery did not revoke a temporary setup session');
    $importId=(int)$pdo->query("SELECT id FROM students WHERE student_number='IMPORT-EMAIL'")->fetchColumn();
    httpCheck($admin->request('/php/admin/resend-student-password.php')['status']===405,'Resend allowed GET');
    httpCheck($admin->request('/php/admin/resend-student-password.php',['csrf'=>'bad','student_id'=>(string)$importId])['status']===403,'Resend ignored CSRF');
    httpCheck($admin->request('/php/admin/resend-student-password.php',['csrf'=>$admin->csrf,'student_id'=>(string)$id])['status']===422,'Resend overwrote completed account');
    $hash=$pdo->query('SELECT password_hash FROM students WHERE id='.$importId)->fetchColumn();
    $response=$admin->request('/php/admin/resend-student-password.php',['csrf'=>$admin->csrf,'student_id'=>(string)$importId]);
    httpCheck($response['status']===502 && str_contains($response['body'],'Delivery failed'),'Resend did not clearly report failed delivery');
    httpCheck($hash!==$pdo->query('SELECT password_hash FROM students WHERE id='.$importId)->fetchColumn(),'Failed resend reused old hash');
    $office=new SetupBrowser($base);$office->token();
    $office->request('/php/auth/admin_login.php',['csrf'=>$office->csrf,'student_number'=>'fixture.registrar','password'=>'Fixture-office-password']);
    $office->token('/admin/results.php');
    httpCheck($office->request('/php/admin/resend-student-password.php',['csrf'=>$office->csrf,'student_id'=>(string)$importId])['status']===403,'Registrar issued credentials');
    $anonymous=new SetupBrowser($base);
    httpCheck(str_ends_with($anonymous->request('/module/module-results.html')['location'],'/login.php'),'Unauthenticated direct fragment access');
    echo "PASS: $checks HTTP setup/import checks; disposable database only; no real messages sent.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo=null;
    if ($created) $source->exec('DROP DATABASE ' . $database);
    if (is_dir($temporary)) removeSetupTree($temporary, $temporary);
}
