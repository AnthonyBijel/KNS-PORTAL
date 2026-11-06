<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
$source = $pdo;
$root = dirname(__DIR__);
$database = 'kns_export_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/kns-export-' . bin2hex(random_bytes(8));
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
        for ($attempt=0; $attempt<20 && !@rmdir($path); $attempt++) usleep(100000);
    } else { for ($attempt=0; $attempt<20 && !@unlink($path); $attempt++) usleep(100000); }
}
final class SetupBrowser
{
    public array $cookies = [];
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
        return ['status' => $status, 'body' => $body, 'location' => $location, 'headers' => $headers];
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

require_once __DIR__ . '/../php/admin/student-list-export-common.php';
try {
    mkdir($app,0700,true);mkdir($temporary.'/sessions');
    $source->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
    $dsn="mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo=new PDO($dsn,$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    // Schema only in dependency order; FK checks remain enabled.
    $pending=array_fill_keys($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),true);
    while($pending){
        $progress=false;
        foreach(array_keys($pending) as $table){
            $refs=$source->prepare('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $refs->execute([$table]);$blocked=false;
            foreach($refs->fetchAll(PDO::FETCH_COLUMN) as $ref)if($ref!==$table&&isset($pending[$ref]))$blocked=true;
            if($blocked)continue;
            $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$table).chr(96);
            $pdo->exec($source->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1]);unset($pending[$table]);$progress=true;
        }
        if(!$progress)throw new RuntimeException('Cyclic fixture schema dependency');
    }
    $adminInsert=$pdo->prepare('INSERT INTO admin_account(username,password_hash,admin_name,role,school_name,school_email,school_contact) VALUES(?,?,?,?,?,?,?)');
    foreach(['admin'=>'Administrator','registrar'=>'Registrar','osa'=>'OSA'] as $name=>$role)$adminInsert->execute(['export.'.$name,password_hash('Export-fixture-password',PASSWORD_DEFAULT),'Export '.$role,$role,'Test School',$name.'@example.invalid','09171234567']);
    $insert=$pdo->prepare('INSERT INTO students(student_number,password_hash,first_name,middle_name,last_name,suffix,course,year_level,section,academic_year,status,requires_password_change,archived_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?)');
    for($i=1;$i<=32;$i++){
        $number=$i===1?'00000000000000000123':'LIST-'.sprintf('%03d',$i);
        $first=$i===2?'=1+1':($i===3?'<img src=x onerror=alert(1)>':'First');
        $insert->execute([$number,password_hash('Student-fixture-password',PASSWORD_DEFAULT),$first,'Middle','Student'.sprintf('%03d',$i),'Jr.',$i===28?'BSBA':'BSCS',$i===29?2:3,$i===30?'BSCS-3B':'BSCS-3A',$i===31?'2027-2028':'2026-2027','Active',$i===32?'2026-01-01 00:00:00':null]);
    }
    $before=$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach(['php','admin','dashboard','module','request','config'] as $dir)copySetupTree($root.'/'.$dir,$app.'/'.$dir);
    foreach(['login.php','change-password.php'] as $file)copy($root.'/'.$file,$app.'/'.$file);
    mkdir($app.'/vendor');
    file_put_contents($app.'/vendor/autoload.php','<?php return require '.var_export($root.'/vendor/autoload.php',true).';');
    file_put_contents($app.'/db.php','<?php $pdo=new PDO('.var_export($dsn,true).','.var_export($username,true).','.var_export($password,true).',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);');
    file_put_contents($temporary.'/provider.php','<?php return [];');
    $router= <<<'ROUTER'
<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(is_file($_SERVER['DOCUMENT_ROOT'].$path))return false;
$root=ROOT_LITERAL;
$relative=preg_replace('~^/kns_portal/~','',$path);
$file=realpath($root.'/'.$relative);
$types=['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','woff2'=>'font/woff2'];
$ext=strtolower(pathinfo($file?:'',PATHINFO_EXTENSION));
if(!$file||!str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$root).'/')||!isset($types[$ext])){http_response_code(404);exit;}
header('Content-Type: '.$types[$ext]);readfile($file);
ROUTER;
    file_put_contents($temporary.'/router.php',str_replace('ROOT_LITERAL',var_export($root,true),$router));
    $environment=getenv();foreach(['KNS_SMTP_USERNAME','KNS_SMTP_PASSWORD','KNS_SMTP_FROM_EMAIL','KNS_TWILIO_ACCOUNT_SID','KNS_TWILIO_AUTH_TOKEN','KNS_TWILIO_FROM_NUMBER'] as $name)$environment[$name]='';
    $environment['KNS_PASSWORD_RESET_CONFIG']=$temporary.'/provider.php';
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$temporary.'/sessions','-S',$address,'-t',$web,$temporary.'/router.php'],[0=>['pipe','r'],1=>['file',$temporary.'/server.log','a'],2=>['file',$temporary.'/server.log','a']],$pipes,$root,$environment,['bypass_shell'=>true]);
    if(!is_resource($server))throw new RuntimeException('Could not start isolated HTTP server');fclose($pipes[0]);
    for($attempt=0;$attempt<60;$attempt++){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(100000);}
    $base='http://'.$address.'/kns_portal';
    mkdir($app.'/downloads');copy($root.'/downloads/KNS_Student_List_Template.xlsx',$app.'/downloads/KNS_Student_List_Template.xlsx');
    $endpoint='/php/admin/student-list-export.php';
    $anonymous=new SetupBrowser($base);
    httpCheck($anonymous->request($endpoint,null,'application/json')['status']===401,'Anonymous options allowed');
    httpCheck($anonymous->request($endpoint,['action'=>'preview'],'application/json')['status']===401,'Anonymous preview allowed');
    $clients=[];
    foreach(['admin'=>200,'registrar'=>200,'osa'=>403] as $name=>$status){
        $client=new SetupBrowser($base);$client->token();
        $client->request('/php/auth/admin_login.php',['csrf'=>$client->csrf,'student_number'=>'export.'.$name,'password'=>'Export-fixture-password']);
        $client->token();$response=$client->request($endpoint,null,'application/json');
        httpCheck($response['status']===$status,'Export role access incorrect: '.$name);
        $clients[$name]=$client;
    }
    $admin=$clients['admin'];$registrar=$clients['registrar'];
    $options=json_decode($admin->request($endpoint,null,'application/json')['body'],true);
    httpCheck(count($options['cohorts'])===5,'Cohorts duplicated or included archived students');
    $filters=['academic_year'=>'2026-2027','course'=>'BSCS','year_level'=>'3','section'=>'BSCS-3A'];
    $request=$filters+['action'=>'preview','csrf'=>$admin->csrf];
    httpCheck($admin->request($endpoint,array_replace($request,['csrf'=>'bad']),'application/json')['status']===403,'Preview ignored CSRF');
    foreach([['year_level'=>'0'],['academic_year'=>'2026-2028'],['course'=>''],['section'=>str_repeat('x',51)],['course'=>str_repeat('x',151)]] as $invalid){
        httpCheck($admin->request($endpoint,array_replace($request,$invalid),'application/json')['status']===422,'Invalid export filters accepted');
    }
    $invalid=$request;unset($invalid['course']);$invalid['course[]']='bad';
    httpCheck($admin->request($endpoint,$invalid,'application/json')['status']===422,'Array filter accepted');
    $response=$admin->request($endpoint,$request,'application/json');$preview=json_decode($response['body'],true);
    httpCheck($response['status']===200 && $preview['total']===27 && count($preview['students'])===27,'Preview missed students past table pagination');
    httpCheck(array_keys($preview['students'][0])===['student_number','full_name','course','section','year_level','academic_year'],'Export exposed extra fields');
    httpCheck($preview['students'][0]['full_name']==='First Middle Student001 Jr.','Full name omitted middle name or suffix');
    httpCheck($preview['students'][0]['student_number']==='00000000000000000123','Preview changed student number');
    httpCheck($preview['students'][26]['student_number']==='LIST-027','Preview sort/exclusions incorrect');
    $download=array_replace($request,['action'=>'download','signature'=>$preview['signature']]);
    foreach([['signature'=>''],['signature'=>str_repeat('a',64)],['section'=>'BSCS-3B']] as $tamper){
        httpCheck($admin->request($endpoint,array_replace($download,$tamper),'application/json')['status']===409,'Unreviewed selection downloaded');
    }
    httpCheck($admin->request($endpoint,array_replace($download,['csrf'=>'bad']),'application/json')['status']===403,'Download ignored CSRF');
    $response=$admin->request($endpoint,$download,'application/json');
    httpCheck($response['status']===200 && str_starts_with($response['body'],'PK'),'Download did not return XLSX');
    httpCheck(str_contains(implode('',$response['headers']),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),'Incorrect workbook MIME');
    httpCheck(str_contains(implode('',$response['headers']),'KNS_Students_2026-2027_BSCS_Year-3_BSCS-3A.xlsx'),'Download filename omitted cohort');
    $workbook=$temporary.'/students.xlsx';file_put_contents($workbook,$response['body']);
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($workbook);$sheet=$book->getActiveSheet();
    httpCheck($sheet->getHighestDataRow()===35 && $sheet->getHighestDataColumn()==='F','Excel row/column count incorrect');
    httpCheck($sheet->rangeToArray('A8:F8')[0]===['Student Number','Full Name','Course','Section','Year Level','Academic Year'],'Excel columns incorrect');
    httpCheck($sheet->getCell('A9')->getValue()==='00000000000000000123' && $sheet->getCell('A9')->getDataType()==='s','Excel changed long/zero-prefixed number');
    httpCheck($sheet->getCell('B10')->getValue()==='=1+1 Middle Student002 Jr.' && $sheet->getCell('B10')->getDataType()==='s','Excel interpreted a student name as a formula');
    httpCheck($sheet->getCell('B6')->getValue()===27 && $sheet->getCell('B3')->getValue()==='2026-2027','Workbook summary incorrect');
    httpCheck($sheet->getAutoFilter()->getRange()==='A8:F35' && $sheet->getFreezePane()==='C9','Workbook filter/frozen headings missing');
    httpCheck(count($sheet->getMergeCells())===7,'Template layout changed');
    $book->disconnectWorksheets();
    httpCheck($pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Export modified student records before refresh test');
    $pdo->prepare('UPDATE students SET first_name=? WHERE student_number=?')->execute(['Changed','LIST-027']);
    httpCheck($admin->request($endpoint,$download,'application/json')['status']===409,'Changed student records bypassed preview');
    $pdo->prepare('UPDATE students SET first_name=? WHERE student_number=?')->execute(['First','LIST-027']);
    $before=$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $empty=json_decode($admin->request($endpoint,array_replace($request,['section'=>'NO-MATCH']),'application/json')['body'],true);
    httpCheck($empty['total']===0 && $empty['students']===[],'Empty cohort returned other students');
    httpCheck($admin->request($endpoint,array_replace($download,['section'=>'NO-MATCH','signature'=>$empty['signature']]),'application/json')['status']===422,'Empty export allowed');
    $injection=json_decode($admin->request($endpoint,array_replace($request,['course'=>"BSCS' OR 1=1 --"]),'application/json')['body'],true);
    httpCheck($injection['total']===0,'Export filters permitted SQL injection');
    $registrarPreview=json_decode($registrar->request($endpoint,array_replace($request,['csrf'=>$registrar->csrf]),'application/json')['body'],true);
    httpCheck($registrarPreview['total']===27,'Registrar preview failed');
    httpCheck($registrar->request($endpoint,array_replace($download,['csrf'=>$registrar->csrf,'signature'=>$registrarPreview['signature']]),'application/json')['status']===200,'Registrar download failed');
    httpCheck($registrar->request($endpoint,array_replace($download,['csrf'=>$registrar->csrf]),'application/json')['status']===409,'Another session reused a preview signature');
    httpCheck($clients['osa']->request($endpoint,array_replace($request,['csrf'=>$clients['osa']->csrf]),'application/json')['status']===403,'OSA preview allowed');
    $student=new SetupBrowser($base);$student->login('LIST-004','Student-fixture-password');
    httpCheck(in_array($student->request($endpoint,null,'application/json')['status'],[401,403],true),'Student export access allowed');
    httpCheck($pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Export modified student records');
    if(in_array('--browser',$argv,true)){
        $artifacts=getenv('KNS_TEST_ARTIFACTS')?:sys_get_temp_dir().'/kns-student-export-review';
        if(!is_dir($artifacts))mkdir($artifacts,0700,true);
        $config=$temporary.'/browser.json';
        file_put_contents($config,json_encode(['base'=>$base,'cookies'=>array_values($admin->cookies),'registrar_cookies'=>array_values($registrar->cookies),'temporary'=>$temporary,'artifacts'=>$artifacts],JSON_THROW_ON_ERROR));
        $browser=proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',$root.'/tests/student_list_export_browser.ps1','-ConfigPath',$config],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,null,['bypass_shell'=>true]);fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($browser)!==0)throw new RuntimeException('Browser checks failed: '.$output.$errors);
        echo $output;
    }
    echo "PASS: $checks student-list export/data/HTTP checks; disposable fixtures only.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $pdo=null;if($created)$source->exec('DROP DATABASE '.$database);
    if(is_dir($temporary))removeSetupTree($temporary,$temporary);
}
