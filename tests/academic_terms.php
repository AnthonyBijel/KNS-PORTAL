<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
$source = $pdo;
$root = dirname(__DIR__);
$database = 'kns_term_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/kns-terms-' . bin2hex(random_bytes(8));
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
function termReject(callable $action, int $status, string $label): void
{
    try { $action(); } catch (KnsAcademicTermError $error) { httpCheck($error->status === $status, $label); return; }
    throw new RuntimeException($label . ' unexpectedly succeeded');
}
function termStudent(PDO $pdo, string $number = 'TERM-STUDENT'): array
{
    $statement=$pdo->prepare('SELECT * FROM students WHERE student_number=?');$statement->execute([$number]);return $statement->fetch(PDO::FETCH_ASSOC);
}
try {
    mkdir($app,0700,true);mkdir($temporary.'/sessions');
    $source->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
    $dsn="mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo=new PDO($dsn,$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row){
        $name='`'.str_replace('`','``',$row[0]).'`';$pdo->exec($source->query('SHOW CREATE TABLE '.$name)->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $today=new DateTimeImmutable('today',knsAcademicTermZone());
    $schoolYear=(int)$today->format('Y');$priorYear=($schoolYear-1).'-'.$schoolYear;$currentYear=$schoolYear.'-'.($schoolYear+1);
    $passwordHash=password_hash('Fixture-student-password',PASSWORD_DEFAULT);
    $statement=$pdo->prepare('INSERT INTO students(student_number,password_hash,first_name,last_name,course,year_level,section,status,academic_year,semester,profile_photo,email) VALUES(?,?,?,?,?,1,?,?,?,?,?,?)');
    $statement->execute(['TERM-STUDENT',$passwordHash,'Example','Student','BSCS','OLD','Active',$priorYear,'First Semester','uploads/students/fixture.png','student@example.invalid']);
    $studentId=(int)$pdo->lastInsertId();
    $statement=$pdo->prepare('INSERT INTO subject_grades(student_number,academic_year,semester,subject_code,subject_name,overall_grade) VALUES(?,?,?,?,?,?)');
    $statement->execute(['TERM-STUDENT',str_replace('-',' - ',$priorYear),'First Semester','CS101','Historical subject',91]);
    $statement=$pdo->prepare('INSERT INTO grade_assessments(student_number,academic_year,semester,grading_period,subject_code,subject_name,assessment_type,assessment_name,score,max_score) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $statement->execute(['TERM-STUDENT',$priorYear,'1st Sem','Prelim','CS101','Historical subject','quiz','Historical quiz',18,20]);
    $originalGrades=$pdo->query('SELECT * FROM subject_grades')->fetchAll(PDO::FETCH_ASSOC);
    $originalAssessments=$pdo->query('SELECT * FROM grade_assessments')->fetchAll(PDO::FETCH_ASSOC);
    $migration=file_get_contents($root.'/database/migrations/20261105_academic_term_management.sql');
    $pdo->exec($migration);$pdo->exec($migration);
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===1,'Migration duplicated enrollment snapshots');
    httpCheck(termStudent($pdo)['password_hash']===$passwordHash && termStudent($pdo)['profile_photo']==='uploads/students/fixture.png','Migration changed password/photo');
    httpCheck($pdo->query('SELECT * FROM subject_grades')->fetchAll(PDO::FETCH_ASSOC)===$originalGrades,'Migration changed grades');
    httpCheck($pdo->query('SELECT * FROM grade_assessments')->fetchAll(PDO::FETCH_ASSOC)===$originalAssessments,'Migration changed assessments');
    $beforeCalendarSetup=new KnsStudentAccountSetup($pdo,static function():void{throw new RuntimeException('Unexpected password delivery');});
    $beforeCalendarSetup->saveImportedStudent(['student_number'=>'TERM-STUDENT','section'=>'OLD']);
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===1,'Identical legacy labels duplicated the migration snapshot');
    $calendar=new KnsAcademicTerms($pdo);
    httpCheck($calendar->calendar()['current']===null && $calendar->calendar()['terms']===[],'Empty calendar is not empty');
    $prior=$calendar->schedule(['academic_year'=>$priorYear,'semester'=>'1st Semester','start_date'=>$today->modify('-120 days')->format('Y-m-d'),'official_end_date'=>$today->modify('-1 day')->format('Y-m-d')],1);
    $current=$calendar->schedule(['academic_year'=>$currentYear,'semester'=>'1st Semester','start_date'=>$today->format('Y-m-d'),'official_end_date'=>$today->modify('+30 days')->format('Y-m-d')],1);
    $future=$calendar->schedule(['academic_year'=>$currentYear,'semester'=>'2nd Semester','start_date'=>$today->modify('+31 days')->format('Y-m-d'),'official_end_date'=>$today->modify('+90 days')->format('Y-m-d')],1);
    $initialSnapshot=$pdo->query('SELECT * FROM student_enrollments ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    httpCheck((int)$initialSnapshot['term_id']===$prior['id'],'Legacy snapshot was not associated with saved dates');
    foreach([['-1 second','Scheduled'],['+0 seconds','Active']] as [$offset,$state]){
        $instant=new DateTimeImmutable($current['start_date'].' 00:00:00',knsAcademicTermZone());
        httpCheck(knsAcademicTermStatus($current,$instant->modify($offset))===$state,'Start boundary incorrect');
    }
    $endInstant=new DateTimeImmutable($current['official_end_date'].' 23:59:59',knsAcademicTermZone());
    httpCheck(knsAcademicTermStatus($current,$endInstant)==='Active','Term ended before official end date finished');
    httpCheck(knsAcademicTermStatus($current,$endInstant->modify('+1 second'))==='Completed','Term did not complete after end date');
    httpCheck(knsAcademicTermStatus($current,$endInstant->setTimezone(new DateTimeZone('UTC')))==='Active','Host/UTC timezone changed school date');
    httpCheck(knsAcademicTermStatus($future,$today)==='Scheduled','Future term activated early');
    termReject(fn()=>$calendar->schedule(array_replace($current,['start_date'=>'invalid']),1),422,'Invalid date accepted');
    termReject(fn()=>$calendar->schedule(array_replace($current,['start_date'=>$current['official_end_date'],'official_end_date'=>$current['start_date']]),1),422,'Reversed dates accepted');
    termReject(fn()=>$calendar->schedule(array_replace($current,['academic_year'=>'2026-2028']),1),422,'Non-consecutive year accepted');
    termReject(fn()=>$calendar->schedule($current,1),409,'Duplicate term accepted');
    termReject(fn()=>$calendar->schedule(array_replace($future,['semester'=>'Summer','start_date'=>$current['official_end_date']]),1),409,'Inclusive boundary overlap accepted');
    $sent=0;
    $setup=new KnsStudentAccountSetup($pdo,function()use(&$sent):void{$sent++;});
    knsSyncStudentTerm($pdo,$studentId,$today->modify('-1 day')->setTime(23,59,59));
    httpCheck(termStudent($pdo)['status']==='Active','Access ended during official end date');
    knsSyncStudentTerm($pdo,$studentId,$today);
    httpCheck(termStudent($pdo)['status']==='Inactive' && (int)termStudent($pdo)['term_auto_inactive']===1,'Ended enrollment did not deactivate account');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSIT','section'=>'CURRENT','year_level'=>2]);
    $enrolled=termStudent($pdo);
    httpCheck((int)$enrolled['id']===$studentId && $enrolled['status']==='Active' && $enrolled['course']==='BSIT','Re-enrollment did not reuse/reactivate account');
    httpCheck($enrolled['password_hash']===$passwordHash && $enrolled['profile_photo']==='uploads/students/fixture.png' && $sent===0,'Re-enrollment replaced credentials/photo or sent password');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSA','section'=>'FUTURE','year_level'=>3]);
    httpCheck(termStudent($pdo)['course']==='BSIT' && termStudent($pdo)['section']==='CURRENT' && termStudent($pdo)['status']==='Active','Future enrollment replaced current profile/access');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSA','section'=>'FUTURE','year_level'=>3]);
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===$count,'Identical reimport duplicated enrollment');
    knsSyncStudentTerm($pdo,$studentId,$endInstant);
    httpCheck(termStudent($pdo)['status']==='Active' && termStudent($pdo)['course']==='BSIT','Current enrollment ended early');
    knsSyncStudentTerm($pdo,$studentId,$endInstant->modify('+1 second'));
    httpCheck(termStudent($pdo)['status']==='Active' && termStudent($pdo)['course']==='BSA','Scheduled re-enrollment did not activate on start date');
    $afterFuture=new DateTimeImmutable($future['official_end_date'].' 23:59:59',knsAcademicTermZone());
    knsSyncStudentTerm($pdo,$studentId,$afterFuture->modify('+1 second'));
    httpCheck(termStudent($pdo)['status']==='Inactive','Final enrollment did not expire');
    knsSyncStudentTerm($pdo,$studentId,$today);
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSED','section'=>'TRANSFER']);
    httpCheck(termStudent($pdo)['course']==='BSED' && (int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()>$count,'Transfer did not append history');
    httpCheck($pdo->query('SELECT * FROM student_enrollments ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC)===$initialSnapshot,'Import overwrote earlier course/section snapshot');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','status'=>'Inactive']);
    knsSyncStudentTerm($pdo,$studentId,$today);
    httpCheck(termStudent($pdo)['status']==='Inactive' && !(int)termStudent($pdo)['term_auto_inactive'],'Manual inactive account was auto-reactivated');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','status'=>'Graduated']);
    knsSyncStudentTerm($pdo,$studentId,$today);
    httpCheck(termStudent($pdo)['status']==='Graduated','Graduated account was auto-reactivated');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','status'=>'Active']);
    httpCheck(termStudent($pdo)['status']==='Active','Intentional re-enrollment did not restore access');
    httpCheck(count($calendar->records($prior['id'],'grades',1)['records'])===1,'Legacy grade labels unavailable in history');
    httpCheck(count($calendar->records($prior['id'],'assessments',1)['records'])===1,'Legacy assessment labels unavailable in history');
    httpCheck($pdo->query('SELECT * FROM subject_grades')->fetchAll(PDO::FETCH_ASSOC)===$originalGrades && $pdo->query('SELECT * FROM grade_assessments')->fetchAll(PDO::FETCH_ASSOC)===$originalAssessments,'Enrollment transitions modified historical scores');
    $setup->saveImportedStudent(['student_number'=>'FUTURE-STUDENT','first_name'=>'Future','last_name'=>'Student','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSCS','section'=>'FUTURE','year_level'=>1,'status'=>'Active','email'=>'future@example.invalid']);
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Inactive','Future-only student received early access');
    // Create authenticated fixtures in the disposable database only.
    $adminInsert=$pdo->prepare('INSERT INTO admin_account(username,password_hash,admin_name,role,school_name,school_email,school_contact) VALUES(?,?,?,?,?,?,?)');
    foreach(['admin'=>'Administrator','registrar'=>'Registrar','osa'=>'OSA'] as $name=>$role)$adminInsert->execute(['fixture.'.$name,password_hash('Fixture-staff-password',PASSWORD_DEFAULT),'Test '.$role,$role,'Test School',$name.'@example.invalid','09171234567']);
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
    $endpoint='/php/admin/academic-terms.php';
    $anonymous=new SetupBrowser($base);
    httpCheck($anonymous->request($endpoint,null,'application/json')['status']===401,'Anonymous calendar access allowed');
    $admin=new SetupBrowser($base);$admin->token();$admin->request('/php/auth/admin_login.php',['csrf'=>$admin->csrf,'student_number'=>'fixture.admin','password'=>'Fixture-staff-password']);$admin->token('/admin/results.php');
    $response=$admin->request($endpoint,null,'application/json');$data=json_decode($response['body'],true);
    httpCheck($response['status']===200 && $data['current']['id']===$current['id'],'Term API did not return real current term');
    httpCheck($data['timezone']==='Asia/Manila' && $data['semesters']===KNS_TERM_SEMESTERS,'Term conventions changed');
    $summer=['csrf'=>$admin->csrf,'academic_year'=>$currentYear,'semester'=>'Summer','start_date'=>$today->modify('+91 days')->format('Y-m-d'),'official_end_date'=>$today->modify('+120 days')->format('Y-m-d')];
    httpCheck($admin->request($endpoint,array_replace($summer,['csrf'=>'bad']),'application/json')['status']===403,'Calendar ignored CSRF');
    httpCheck($admin->request($endpoint,array_replace($summer,['start_date'=>'bad-date']),'application/json')['status']===422,'Calendar accepted invalid date');
    httpCheck($admin->request($endpoint,array_replace($summer,['start_date'=>$future['official_end_date']]),'application/json')['status']===409,'Calendar accepted shared-day overlap');
    foreach(['registrar'=>200,'osa'=>403] as $name=>$expected){
        $staff=new SetupBrowser($base);$staff->token();$staff->request('/php/auth/admin_login.php',['csrf'=>$staff->csrf,'student_number'=>'fixture.'.$name,'password'=>'Fixture-staff-password']);$staff->token();
        httpCheck($staff->request($endpoint,null,'application/json')['status']===$expected,'Office read permissions changed');
        httpCheck($staff->request($endpoint,array_replace($summer,['csrf'=>$staff->csrf]),'application/json')['status']===403,'Office scheduled term');
    }
    $student=new SetupBrowser($base);httpCheck(str_ends_with($student->login('TERM-STUDENT','Fixture-student-password')['location'],'/dashboard/dashboard.php'),'Active enrollment login failed');
    httpCheck(in_array($student->request($endpoint,null,'application/json')['status'],[401,403],true),'Student accessed term administration');
    // Simulate a calendar boundary in this disposable fixture after login.
    $statement=$pdo->prepare('UPDATE academic_terms SET start_date=?,official_end_date=? WHERE id=?');
    $statement->execute([$today->modify('-2 days')->format('Y-m-d'),$today->modify('-1 day')->format('Y-m-d'),$current['id']]);
    $response=$student->request('/dashboard/dashboard.php');
    httpCheck(str_ends_with($response['location'],'/login.php') && termStudent($pdo)['status']==='Inactive','Existing session bypassed enrollment end date');
    $statement->execute([$current['start_date'],$current['official_end_date'],$current['id']]);
    knsSyncStudentTerm($pdo,$studentId);
    $past=new SetupBrowser($base);
    $setup->saveImportedStudent(['student_number'=>'ENDED-STUDENT','first_name'=>'Ended','last_name'=>'Student','academic_year'=>$priorYear,'semester'=>'1st Semester','course'=>'BSCS','section'=>'OLD','year_level'=>1,'status'=>'Active','email'=>'ended@example.invalid']);
    $endedId=(int)termStudent($pdo,'ENDED-STUDENT')['id'];
    $statement=$pdo->prepare('UPDATE students SET password_hash=?,requires_password_change=0,temporary_password_expires_at=NULL,status=\'Active\',term_auto_inactive=0 WHERE id=?');$statement->execute([$passwordHash,$endedId]);
    httpCheck(str_ends_with($past->login('ENDED-STUDENT','Fixture-student-password')['location'],'/login.php') && termStudent($pdo,'ENDED-STUDENT')['status']==='Inactive','Expired enrollment bypassed login');
    foreach(['enrollments','grades','assessments'] as $kind){$response=$admin->request($endpoint.'?term_id='.$prior['id'].'&kind='.$kind,null,'application/json');httpCheck($response['status']===200 && json_decode($response['body'],true)['total']>=1,'Term history unavailable: '.$kind);}
    // Exercise existing student and grade workbook endpoints without altering their UI.
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Student Upload');
    $sheet->fromArray([['student_number','first_name','last_name','course','year_level','section','academic_year','semester','status'],['TERM-STUDENT','Example','Student','BSBA',3,'NEW-COURSE',$currentYear,'2nd Semester','Active']]);
    $workbook=$temporary.'/enrollment.xlsx';\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response=$admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','enrollment.xlsx')]);
    httpCheck($response['status']===200 && str_contains($response['body'],'(0 new, 1 updated)'),'Enrollment workbook created another account');
    httpCheck(termStudent($pdo)['course']==='BSED' && termStudent($pdo)['status']==='Active','Future workbook changed current course/access');
    $gradeBook=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$gradeSheet=$gradeBook->getActiveSheet();$gradeSheet->setTitle('Subject Grades');
    $gradeSheet->fromArray([['student_number','academic_year','semester','subject_code','subject_name','prelim_grade','midterm_grade','final_grade','overall_grade'],['TERM-STUDENT',$currentYear,'2nd Semester','CS101','Future subject',80,85,90,85]]);
    $assessmentSheet=$gradeBook->createSheet();$assessmentSheet->setTitle('Assessments');
    $assessmentSheet->fromArray([['student_number','academic_year','semester','grading_period','subject_code','subject_name','assessment_type','assessment_name','score','max_score','date_posted','remarks'],['TERM-STUDENT',$currentYear,'2nd Semester','Prelim','CS101','Future subject','quiz','Historical quiz',19,20,null,null]]);
    $gradeFile=$temporary.'/grades.xlsx';\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($gradeBook,'Xlsx')->save($gradeFile);
    $response=$admin->request('/php/admin/upload_grades.php',['csrf'=>$admin->csrf,'grades_excel'=>new CURLFile($gradeFile,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','grades.xlsx')],'application/json');
    httpCheck($response['status']===200 && json_decode($response['body'],true)['ok'],'New term grade import failed');
    httpCheck($pdo->query('SELECT * FROM subject_grades ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC)===$originalGrades[0],'New term overwrote old subject grade');
    httpCheck($pdo->query('SELECT * FROM grade_assessments ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC)===$originalAssessments[0] && (int)$pdo->query('SELECT COUNT(*) FROM grade_assessments')->fetchColumn()===2,'New term overwrote old assessment');
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM students WHERE student_number=\'TERM-STUDENT\'')->fetchColumn()===1,'Student account was duplicated');
    $history=$calendar->records($future['id'],'enrollments',1);
    httpCheck(count($history['records'])>=2,'Course transfer revisions unavailable in history');
    if(in_array('--browser',$argv,true)){
        $artifacts=getenv('KNS_TEST_ARTIFACTS')?:sys_get_temp_dir().'/kns-academic-term-review';
        if(!is_dir($artifacts))mkdir($artifacts,0700,true);
        $browserConfig=$temporary.'/browser.json';
        file_put_contents($browserConfig,json_encode(['base'=>$base,'cookies'=>array_values($admin->cookies),'temporary'=>$temporary,'artifacts'=>$artifacts,'summer'=>$summer],JSON_THROW_ON_ERROR));
        $command=['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',$root.'/tests/academic_terms_browser.ps1','-ConfigPath',$browserConfig];
        $browser=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$browserPipes,$root,null,['bypass_shell'=>true]);fclose($browserPipes[0]);
        $output=stream_get_contents($browserPipes[1]);fclose($browserPipes[1]);$errors=stream_get_contents($browserPipes[2]);fclose($browserPipes[2]);
        $exit=proc_close($browser);if($exit!==0)throw new RuntimeException('Browser checks failed: '.$output.$errors);echo $output;
    }else{
        $response=$admin->request($endpoint,$summer,'application/json');httpCheck($response['status']===200 && json_decode($response['body'],true)['ok'],'Academic term save failed');
    }
    httpCheck(count($calendar->calendar()['terms'])===4,'Scheduling overwrote term history');
    httpCheck($calendar->calendar()['current']['id']===$current['id'],'Scheduling future term replaced current term');
    // Concurrent calls for overlapping dates must commit at most one term.
    $raceYear=($schoolYear+1).'-'.($schoolYear+2);
    $raceBase=['academic_year'=>$raceYear,'start_date'=>$today->modify('+121 days')->format('Y-m-d'),'official_end_date'=>$today->modify('+150 days')->format('Y-m-d')];
    $children=[];
    foreach(['1st Semester','2nd Semester'] as $raceSemester){
        $input=array_replace($raceBase,['semester'=>$raceSemester]);
        $childCode='<?php require '.var_export($root.'/php/academic-term-common.php',true).';$pdo=new PDO('.var_export($dsn,true).','.var_export($username,true).','.var_export($password,true).',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);usleep(200000);try{(new KnsAcademicTerms($pdo))->schedule('.var_export($input,true).',1);echo 200;}catch(KnsAcademicTermError $e){echo $e->status;}';
        $process=proc_open([PHP_BINARY],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$childPipes,$root,null,['bypass_shell'=>true]);
        $children[]=[$process,$childPipes,$childCode];
    }
    foreach($children as [$process,$childPipes,$childCode]){fwrite($childPipes[0],$childCode);fclose($childPipes[0]);}
    $statuses=[];
    foreach($children as [$process,$childPipes]){
        $statuses[]=(int)stream_get_contents($childPipes[1]);fclose($childPipes[1]);$diagnostic=stream_get_contents($childPipes[2]);fclose($childPipes[2]);
        httpCheck(proc_close($process)===0 && $diagnostic==='','Concurrent scheduler raised an unexpected database error');
    }
    sort($statuses);
    httpCheck($statuses===[200,409] && count($calendar->calendar()['terms'])===5,'Concurrent scheduling accepted overlapping terms');
    echo "PASS: $checks academic-term/data/HTTP checks; disposable fixtures only; no real messages sent.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $pdo=null;if($created)$source->exec('DROP DATABASE '.$database);
    if(is_dir($temporary))removeSetupTree($temporary,$temporary);
}
