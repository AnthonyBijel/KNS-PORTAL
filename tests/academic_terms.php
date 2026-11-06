<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
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
    $statement=$pdo->prepare('SELECT * FROM students WHERE student_number=?');$statement->execute([$number]);return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
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
    $activationMigration=file_get_contents($root.'/database/migrations/20261105_student_term_activation.sql');
    $pdo->exec($activationMigration);$pdo->exec($activationMigration);
    $graceMigration=file_get_contents($root.'/database/migrations/20261106_student_term_grace.sql');$pdo->exec($graceMigration);$pdo->exec($graceMigration);
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
    // Account-wide cutoff: neither a missing snapshot nor a NULL term link bypasses expiry.
    $clone=$pdo->prepare("INSERT INTO students(student_number,password_hash,first_name,last_name,course,year_level,section,status,email,academic_year,semester,profile_photo)
        SELECT ?,password_hash,first_name,last_name,course,year_level,section,?,email,NULL,NULL,profile_photo FROM students WHERE id=?");
    foreach(['NO-ENROLLMENT','UNLINKED-ENROLLMENT','SCHEDULE-ONLY'] as $number) {
        $clone->execute([$number,'Active',$studentId]);
        if($number!=='NO-ENROLLMENT')knsEnrollmentSnapshot($pdo,(int)$pdo->lastInsertId(),termStudent($pdo,$number));
    }
    knsSyncTermAccounts($pdo,$today->modify('-1 day')->setTime(23,59,59));
    foreach(['NO-ENROLLMENT','UNLINKED-ENROLLMENT'] as $number)httpCheck(termStudent($pdo,$number)['status']==='Active','Legacy access ended before the official end date: '.$number);
    $legacyBefore=termStudent($pdo,'NO-ENROLLMENT');
    knsSyncTermAccounts($pdo,$today);
    foreach(['NO-ENROLLMENT','UNLINKED-ENROLLMENT'] as $number)httpCheck(termStudent($pdo,$number)['status']==='Inactive','Calendar cutoff missed '.$number);
    knsSyncTermAccounts($pdo,$today);
    $legacyAfter=termStudent($pdo,'NO-ENROLLMENT');
    foreach(['id','password_hash','profile_photo','email','first_name','last_name'] as $field)httpCheck($legacyAfter[$field]===$legacyBefore[$field],'Cutoff changed account data: '.$field);
    $scheduleOnly=termStudent($pdo,'SCHEDULE-ONLY');
    $scheduleOnly['academic_year']=$currentYear;$scheduleOnly['semester']='2nd Semester';$scheduleOnly['status']='Active';
    knsEnrollmentSnapshot($pdo,(int)$scheduleOnly['id'],$scheduleOnly);
    knsSyncStudentTerm($pdo,(int)$scheduleOnly['id'],new DateTimeImmutable($future['start_date'],knsAcademicTermZone()));
    httpCheck(termStudent($pdo,'SCHEDULE-ONLY')['status']==='Inactive','Scheduled/legacy snapshot granted activation');
    $complete=['student_number'=>'NO-ENROLLMENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSCS','year_level'=>1,'section'=>'NEW'];
    $setup->saveImportedStudent($complete);
    httpCheck(termStudent($pdo,'NO-ENROLLMENT')['status']==='Inactive','Missing status approved enrollment');
    $blankHistoryCount=(int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    $setup->saveImportedStudent(array_replace($complete,['status'=>'']));
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===$blankHistoryCount,'Identical blank-status reimport duplicated history');
    httpCheck(termStudent($pdo,'NO-ENROLLMENT')['status']==='Inactive','Blank status approved enrollment');
    $beforeRejected=termStudent($pdo,'NO-ENROLLMENT');
    foreach(['academic_year','semester','course','year_level','section'] as $field) {
        $bad=array_replace($complete,['status'=>'Active']);unset($bad[$field]);
        try{$setup->saveImportedStudent($bad);throw new RuntimeException('Incomplete Active enrollment accepted: '.$field);}
        catch(InvalidArgumentException $error){httpCheck(termStudent($pdo,'NO-ENROLLMENT')===$beforeRejected,'Rejected row changed account: '.$field);}
    }
    foreach([['academic_year'=>$priorYear],['academic_year'=>($schoolYear+3).'-'.($schoolYear+4)],['section'=>'']] as $invalid) {
        try{$setup->saveImportedStudent(array_replace($complete,['status'=>'Active'],$invalid));throw new RuntimeException('Invalid/unsaved/completed term was accepted');}
        catch(InvalidArgumentException $error){httpCheck(termStudent($pdo,'NO-ENROLLMENT')===$beforeRejected,'Rejected term changed account');}
    }
    $beforeFailedHistory=(int)$pdo->query("SELECT COUNT(*) FROM student_enrollments WHERE student_id=".$beforeRejected['id'])->fetchColumn();
    try{$setup->saveImportedStudent(array_replace($complete,['student_number'=>'FAILED-ENROLLMENT','first_name'=>'No','last_name'=>'Contact','status'=>'Active','email'=>null]));throw new RuntimeException('Failed new account import was accepted');}
    catch(InvalidArgumentException $error){httpCheck(!termStudent($pdo,'FAILED-ENROLLMENT'),'Failed enrollment created an account');}
    httpCheck((int)$pdo->query("SELECT COUNT(*) FROM student_enrollments WHERE student_id=".$beforeRejected['id'])->fetchColumn()===$beforeFailedHistory,'Rejected enrollment appended history');
    $setup->saveImportedStudent(['student_number'=>'BLANK-NEW','first_name'=>'Blank','last_name'=>'Status','course'=>'BSCS','year_level'=>1,'section'=>'NEW']);
    httpCheck(termStudent($pdo,'BLANK-NEW')['status']==='Inactive','New blank-status account defaulted to Active');


    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSIT','section'=>'CURRENT','year_level'=>2,'status'=>'Active']);
    $enrolled=termStudent($pdo);
    httpCheck((int)$enrolled['id']===$studentId && $enrolled['status']==='Active' && $enrolled['course']==='BSIT','Re-enrollment did not reuse/reactivate account');
    httpCheck($enrolled['password_hash']===$passwordHash && $enrolled['profile_photo']==='uploads/students/fixture.png' && $sent===0,'Re-enrollment replaced credentials/photo or sent password');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSA','section'=>'FUTURE','year_level'=>3,'status'=>'Active']);
    httpCheck(termStudent($pdo)['course']==='BSIT' && termStudent($pdo)['section']==='CURRENT' && termStudent($pdo)['status']==='Active','Future enrollment replaced current profile/access');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSA','section'=>'FUTURE','year_level'=>3,'status'=>'Active']);
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===$count,'Identical reimport duplicated enrollment');
    knsSyncStudentTerm($pdo,$studentId,$endInstant);
    httpCheck(termStudent($pdo)['status']==='Active' && termStudent($pdo)['course']==='BSIT','Current enrollment ended early');
    knsSyncStudentTerm($pdo,$studentId,$endInstant->modify('+1 second'));
    httpCheck(termStudent($pdo)['status']==='Active' && termStudent($pdo)['course']==='BSA','Scheduled re-enrollment did not activate on start date');
    $afterFuture=new DateTimeImmutable($future['official_end_date'].' 23:59:59',knsAcademicTermZone());
    knsSyncStudentTerm($pdo,$studentId,$afterFuture->modify('+1 second'));
    httpCheck(termStudent($pdo)['status']==='Inactive','Final enrollment did not expire');
    knsSyncStudentTerm($pdo,$studentId,$today);
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSED','section'=>'TRANSFER','year_level'=>2,'status'=>'Active']);
    httpCheck(termStudent($pdo)['course']==='BSED' && (int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()>$count,'Transfer did not append history');
    httpCheck($pdo->query('SELECT * FROM student_enrollments ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC)===$initialSnapshot,'Import overwrote earlier course/section snapshot');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','status'=>'Inactive']);
    knsSyncStudentTerm($pdo,$studentId,$today);
    httpCheck(termStudent($pdo)['status']==='Inactive' && !(int)termStudent($pdo)['term_auto_inactive'],'Manual inactive account was auto-reactivated');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','status'=>'Graduated']);
    knsSyncStudentTerm($pdo,$studentId,$today);
    httpCheck(termStudent($pdo)['status']==='Graduated','Graduated account was auto-reactivated');
    $setup->saveImportedStudent(['student_number'=>'TERM-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSED','section'=>'TRANSFER','year_level'=>2,'status'=>'Active']);
    httpCheck(termStudent($pdo)['status']==='Active','Intentional re-enrollment did not restore access');
    httpCheck(count($calendar->records($prior['id'],'grades',1)['records'])===1,'Legacy grade labels unavailable in history');
    httpCheck(count($calendar->records($prior['id'],'assessments',1)['records'])===1,'Legacy assessment labels unavailable in history');
    httpCheck($pdo->query('SELECT * FROM subject_grades')->fetchAll(PDO::FETCH_ASSOC)===$originalGrades && $pdo->query('SELECT * FROM grade_assessments')->fetchAll(PDO::FETCH_ASSOC)===$originalAssessments,'Enrollment transitions modified historical scores');
    $setup->saveImportedStudent(['student_number'=>'FUTURE-STUDENT','first_name'=>'Future','last_name'=>'Student','academic_year'=>$currentYear,'semester'=>'2nd Semester','course'=>'BSCS','section'=>'FUTURE','year_level'=>1,'status'=>'Active','email'=>'future@example.invalid']);
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Inactive','Future-only student received early access');
    $pending=termStudent($pdo,'FUTURE-STUDENT');
    knsSyncStudentTerm($pdo,(int)$pending['id'],new DateTimeImmutable($future['start_date'].' 00:00:00',knsAcademicTermZone()));
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Active','Approved future enrollment did not activate at start');
    knsSyncStudentTerm($pdo,(int)$pending['id'],$today);
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Inactive','Future enrollment became active before start');
    $setup->saveImportedStudent(['student_number'=>'FUTURE-STUDENT','status'=>'Graduated']);
    knsSyncStudentTerm($pdo,(int)$pending['id'],new DateTimeImmutable($future['start_date'],knsAcademicTermZone()));
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Graduated','Graduated pending account reactivated');
    $setup->saveImportedStudent(['student_number'=>'FUTURE-STUDENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSCS','year_level'=>1,'section'=>'NEW','status'=>'Active']);
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Active','Complete enrollment did not unblock Graduated account');
    knsSyncStudentTerm($pdo,(int)$pending['id'],new DateTimeImmutable($future['start_date'],knsAcademicTermZone()));
    httpCheck(termStudent($pdo,'FUTURE-STUDENT')['status']==='Inactive','Revoked future authorization survived manual blocking');

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
    // Verify password confirmation before any term or linked-record changes.
    $edit=array_replace($current,['action'=>'update','term_id'=>$current['id'],'revision'=>$data['current']['revision'],'csrf'=>$admin->csrf,'official_end_date'=>$today->modify('+29 days')->format('Y-m-d')]);
    $termState=static function()use($pdo):array {
        $state=[];
        foreach(['academic_terms','students','student_enrollments','subject_grades','grade_assessments'] as $table)$state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return $state;
    };
    $beforeEdit=$termState();
    foreach([[],['confirmation_password'=>'wrong-password'],['confirmation_password[]'=>'bad'],['confirmation_password'=>str_repeat('x',4097)]] as $credentials){
        httpCheck($admin->request($endpoint,array_replace($edit,$credentials),'application/json')['status']===422,'Missing/invalid edit password accepted');
        httpCheck($termState()===$beforeEdit,'Rejected password changed records');
    }
    $edit['confirmation_password']='Fixture-staff-password';
    httpCheck($admin->request($endpoint,array_replace($edit,['csrf'=>'bad']),'application/json')['status']===403,'Edit ignored CSRF');
    foreach([
        [['start_date'=>'bad-date'],422],
        [['academic_year'=>'2026-2028'],422],
        [['official_end_date'=>$future['start_date']],409],
        [['academic_year'=>$future['academic_year'],'semester'=>$future['semester']],409],
        [['term_id'=>$prior['id'],'revision'=>knsAcademicTermRevision($prior)],409],
        [['term_id'=>$future['id'],'revision'=>knsAcademicTermRevision($future)],409],
        [['term_id'=>999999,'revision'=>str_repeat('a',64)],404],
        [['revision'=>''],409],
    ] as [$changes,$expected])httpCheck($admin->request($endpoint,array_replace($edit,$changes),'application/json')['status']===$expected,'Invalid/inactive term edit accepted');
    httpCheck($termState()===$beforeEdit,'Rejected edit changed records');
    $response=$admin->request($endpoint,$edit,'application/json');$edited=json_decode($response['body'],true);
    httpCheck($response['status']===200 && $edited['current']['official_end_date']===$edit['official_end_date'] && $edited['current']['id']===$current['id'],'Admin confirmed edit failed');
    httpCheck(!str_contains($response['body'],'Fixture-staff-password') && !str_contains($response['body'],'password_hash'),'Edit exposed credentials');
    httpCheck($admin->request($endpoint,$edit,'application/json')['status']===409,'Stale edit overwrote newer term');
    $restore=array_replace($edit,$current,['revision'=>$edited['current']['revision']]);
    httpCheck($admin->request($endpoint,$restore,'application/json')['status']===200,'Admin restore failed');
    $registrarEdit=new SetupBrowser($base);$registrarEdit->token();$registrarEdit->request('/php/auth/admin_login.php',['csrf'=>$registrarEdit->csrf,'student_number'=>'fixture.registrar','password'=>'Fixture-staff-password']);$registrarEdit->token();
    $registrarCalendar=json_decode($registrarEdit->request($endpoint,null,'application/json')['body'],true);
    httpCheck($registrarCalendar['can_edit'] && !$registrarCalendar['can_schedule'],'Registrar permissions incorrect');
    $registrarInput=array_replace($edit,['csrf'=>$registrarEdit->csrf,'revision'=>$registrarCalendar['current']['revision']]);
    $response=$registrarEdit->request($endpoint,$registrarInput,'application/json');$edited=json_decode($response['body'],true);
    httpCheck($response['status']===200 && $edited['current']['official_end_date']===$edit['official_end_date'],'Registrar confirmed edit failed');
    $restore=array_replace($registrarInput,$current,['revision'=>$edited['current']['revision']]);
    httpCheck($registrarEdit->request($endpoint,$restore,'application/json')['status']===200,'Registrar restore failed');
    $staffIds=$pdo->query('SELECT username,id FROM admin_account')->fetchAll(PDO::FETCH_KEY_PAIR);
    termReject(fn()=>$calendar->update($edit,(int)$staffIds['fixture.osa']),403,'OSA edited term');
    $adminInsert->execute(['fixture.other',password_hash('Different-staff-password',PASSWORD_DEFAULT),'Other Registrar','Registrar','Test School','other@example.invalid','09171234567']);
    termReject(fn()=>$calendar->update(array_replace($edit,['confirmation_password'=>'Different-staff-password']),(int)$staffIds['fixture.admin']),422,'Another staff password confirmed signed-in account');
    $otherId=(int)$pdo->query("SELECT id FROM admin_account WHERE username='fixture.other'")->fetchColumn();
    httpCheck($otherId>0,'Inactive confirmation fixture missing');
    $pdo->prepare('UPDATE admin_account SET is_active=0 WHERE id=?')->execute([$otherId]);
    termReject(fn()=>$calendar->update(array_replace($edit,['confirmation_password'=>'Different-staff-password']),$otherId),403,'Inactive staff edited term');
    // Label corrections retain enrollment links and scores; occupied destinations roll back all changes.
    $pdo->prepare('INSERT INTO subject_grades(student_number,academic_year,semester,subject_code,subject_name,overall_grade) VALUES(?,?,?,?,?,?)')->execute(['TERM-STUDENT',$currentYear,'1st Semester','EDIT101','Edit fixture',93]);
    $editGradeId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO grade_assessments(student_number,academic_year,semester,grading_period,subject_code,subject_name,assessment_type,assessment_name,score,max_score) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(['TERM-STUDENT',$currentYear,'1st Sem','Prelim','EDIT101','Edit fixture','quiz','Edit fixture',19,20]);
    $editAssessmentId=(int)$pdo->lastInsertId();
    $unusedYear=($schoolYear+10).'-'.($schoolYear+11);
    $rename=array_replace($edit,['academic_year'=>$unusedYear,'semester'=>'Summer','official_end_date'=>$current['official_end_date'],'revision'=>$calendar->calendar()['current']['revision']]);
    $calendar->update($rename,(int)$staffIds['fixture.admin']);
    httpCheck(termStudent($pdo)['academic_year']===$unusedYear && termStudent($pdo)['semester']==='Summer','Rename detached current student profile');
    foreach(['enrollments','grades','assessments'] as $recordKind)httpCheck(count($calendar->records($current['id'],$recordKind,1)['records'])>=1,'Rename lost '.$recordKind);
    httpCheck((int)$pdo->query('SELECT overall_grade FROM subject_grades WHERE id='.$editGradeId)->fetchColumn()===93,'Rename changed scores');
    httpCheck($calendar->records($prior['id'],'grades',1)['records'][0]['academic_year']===$originalGrades[0]['academic_year'],'Rename changed historical grades');
    $calendar->update(array_replace($rename,$current,['revision'=>$calendar->calendar()['current']['revision']]),(int)$staffIds['fixture.admin']);
    $pdo->prepare('INSERT INTO grade_assessments(student_number,academic_year,semester,grading_period,subject_code,subject_name,assessment_type,assessment_name,score,max_score) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(['TERM-STUDENT',$unusedYear,'Summer','Prelim','CONFLICT','Conflict fixture','quiz','Conflict fixture',1,2]);
    $conflictId=(int)$pdo->lastInsertId();$beforeConflict=$termState();
    termReject(fn()=>$calendar->update($rename,(int)$staffIds['fixture.admin']),409,'Rename merged existing records');
    httpCheck($termState()===$beforeConflict,'Failed rename did not roll back records');
    $pdo->prepare('DELETE FROM subject_grades WHERE id=?')->execute([$editGradeId]);
    $pdo->prepare('DELETE FROM grade_assessments WHERE id IN (?,?)')->execute([$editAssessmentId,$conflictId]);
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
    httpCheck($response['status']===200 && termStudent($pdo)['status']==='Inactive','Existing session lost grace access');
    foreach(['/module/studentInformation.php','/module/overview.php','/php/module/get-results.php','/php/student/notifications.php','/php/request/records.php'] as $path){
        httpCheck($student->request($path)['status']===200,'Grace access failed: '.$path);
    }
    $student->token('/module/studentInformation.php');
    $photoFile=$temporary.'/grace-photo.png';$photoImage=imagecreatetruecolor(2,2);imagepng($photoImage,$photoFile);imagedestroy($photoImage);
    $photoResponse=$student->request('/php/student/upload_profile_photo.php',['csrf'=>$student->csrf,'profile_photo'=>new CURLFile($photoFile,'image/png','grace.png')],'application/json');
    httpCheck($photoResponse['status']===200,'Grace profile photo workflow was blocked');
    $statement->execute([$current['start_date'],$current['official_end_date'],$current['id']]);
    knsSyncStudentTerm($pdo,$studentId);
    $past=new SetupBrowser($base);
    $setup->saveImportedStudent(['student_number'=>'ENDED-STUDENT','first_name'=>'Ended','last_name'=>'Student','academic_year'=>$priorYear,'semester'=>'1st Semester','course'=>'BSCS','section'=>'OLD','year_level'=>1,'status'=>'Inactive','email'=>'ended@example.invalid']);
    $endedId=(int)termStudent($pdo,'ENDED-STUDENT')['id'];
    $statement=$pdo->prepare('UPDATE students SET password_hash=?,requires_password_change=0,temporary_password_expires_at=NULL,status=\'Active\',term_auto_inactive=0 WHERE id=?');$statement->execute([$passwordHash,$endedId]);
    httpCheck(str_ends_with($past->login('ENDED-STUDENT','Fixture-student-password')['location'],'/dashboard/dashboard.php') && termStudent($pdo,'ENDED-STUDENT')['status']==='Inactive','Term-inactive account lost grace login');
    foreach(['enrollments','grades','assessments'] as $kind){$response=$admin->request($endpoint.'?term_id='.$prior['id'].'&kind='.$kind,null,'application/json');httpCheck($response['status']===200 && json_decode($response['body'],true)['total']>=1,'Term history unavailable: '.$kind);}
    foreach(['NO-ENROLLMENT','UNLINKED-ENROLLMENT'] as $number) {
        $blocked=new SetupBrowser($base);
        httpCheck(str_ends_with($blocked->login($number,'Fixture-student-password')['location'],'/dashboard/dashboard.php'),'Legacy account lost grace login: '.$number);
    }
    $legacySession=new SetupBrowser($base);
    // Independent fixture simulates an old authenticated session with no enrollment history.
    $clone->execute(['SESSION-NO-ENROLLMENT','Active',$studentId]);
    $legacySessionRow=termStudent($pdo,'SESSION-NO-ENROLLMENT');
    // An authorized current enrollment permits login; removing only its fixture snapshots exercises the fallback.
    $setup->saveImportedStudent(['student_number'=>'SESSION-NO-ENROLLMENT','academic_year'=>$currentYear,'semester'=>'1st Semester','course'=>'BSCS','year_level'=>1,'section'=>'TEST','status'=>'Active']);
    $legacySession->login('SESSION-NO-ENROLLMENT','Fixture-student-password');
    $pdo->prepare('DELETE FROM student_enrollments WHERE student_id=?')->execute([$legacySessionRow['id']]);
    $response=$legacySession->request('/php/module/get-results.php',null,'application/json');
    httpCheck($response['status']===200 && termStudent($pdo,'SESSION-NO-ENROLLMENT')['status']==='Inactive','Existing legacy session lost grace API access');

    // Exercise existing student and grade workbook endpoints without altering their UI.
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Student Upload');
    $sheet->fromArray([['student_number','first_name','last_name','course','year_level','section','academic_year','semester','status'],['TERM-STUDENT','Example','Student','BSBA',3,'NEW-COURSE',$currentYear,'2nd Semester','Active']]);
    $workbook=$temporary.'/enrollment.xlsx';\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response=$admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','enrollment.xlsx')]);
    httpCheck($response['status']===200 && str_contains($response['body'],'(0 new, 1 updated)'),'Enrollment workbook created another account');
    httpCheck(termStudent($pdo)['course']==='BSED' && termStudent($pdo)['status']==='Active','Future workbook changed current course/access');
    // Rejected/blank/missing Excel rows cannot reactivate students; Registrar can import valid rows.
    $sheet->fromArray([['NO-ENROLLMENT','Legacy','Student','BSCS',1,'BAD',$currentYear,'1st Semester','']],null,'A2');
    $sheet->setCellValue('I2','');
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response=$admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','blank.xlsx')]);
    httpCheck(termStudent($pdo,'NO-ENROLLMENT')['status']==='Inactive','Blank Excel status reactivated account');
    $sheet->setCellValue('I2','Active');$sheet->setCellValue('G2',($schoolYear+3).'-'.($schoolYear+4));
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $response=$admin->request('/php/admin/upload_students.php',['csrf'=>$admin->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','bad.xlsx')]);
    httpCheck(str_contains($response['body'],'saved current or upcoming') && termStudent($pdo,'NO-ENROLLMENT')['status']==='Inactive','Rejected Excel row reactivated account');
    $sheet->setCellValue('G2',$currentYear);
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($workbook);
    $osa=new SetupBrowser($base);$osa->token();$osa->request('/php/auth/admin_login.php',['csrf'=>$osa->csrf,'student_number'=>'fixture.osa','password'=>'Fixture-staff-password']);$osa->token();
    httpCheck($osa->request('/php/admin/upload_students.php',['csrf'=>$osa->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','valid.xlsx')])['status']===403,'OSA imported enrollment');
    httpCheck($anonymous->request('/php/admin/upload_students.php',['student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','valid.xlsx')],'application/json')['status']===401,'Anonymous import allowed');
    $registrar=new SetupBrowser($base);$registrar->token();$registrar->request('/php/auth/admin_login.php',['csrf'=>$registrar->csrf,'student_number'=>'fixture.registrar','password'=>'Fixture-staff-password']);$registrar->token();
    $response=$registrar->request('/php/admin/upload_students.php',['csrf'=>$registrar->csrf,'student_excel'=>new CURLFile($workbook,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','valid.xlsx')]);
    httpCheck(str_contains($response['body'],'(0 new, 1 updated)') && termStudent($pdo,'NO-ENROLLMENT')['status']==='Active','Registrar could not re-enroll existing account');
    httpCheck(termStudent($pdo,'UNLINKED-ENROLLMENT')['status']==='Inactive','Omitted Excel student was reactivated');
    httpCheck(termStudent($pdo,'NO-ENROLLMENT')['password_hash']===$legacyBefore['password_hash'] && termStudent($pdo,'NO-ENROLLMENT')['profile_photo']===$legacyBefore['profile_photo'],'Workbook re-enrollment changed credentials/photo');
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
        file_put_contents($browserConfig,json_encode(['base'=>$base,'cookies'=>array_values($admin->cookies),'temporary'=>$temporary,'artifacts'=>$artifacts,'summer'=>$summer,'staff_password'=>'Fixture-staff-password','registrar_cookies'=>array_values($registrar->cookies)],JSON_THROW_ON_ERROR));
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
    // Enforce the exact expiry on login and a session established during grace.
    $expiredSession=new SetupBrowser($base);$expiredSession->login('UNLINKED-ENROLLMENT','Fixture-student-password');
    $pdo->prepare('UPDATE students SET term_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE student_number=?')->execute(['UNLINKED-ENROLLMENT']);
    $expiredResponse=$expiredSession->request('/php/request/records.php',null,'application/json');
    httpCheck($expiredResponse['status']===401 && termStudent($pdo,'UNLINKED-ENROLLMENT')['archived_at']!==null,'Expired session retained normal feature access');
    $expiredLogin=new SetupBrowser($base);
    httpCheck(str_ends_with($expiredLogin->login('UNLINKED-ENROLLMENT','Fixture-student-password')['location'],'/login.php'),'Archived account could log in');
    $archivedList=$admin->request('/admin/results.php');
    httpCheck(!str_contains($archivedList['body'],'UNLINKED-ENROLLMENT'),'Archived identity remained in Students List');
    // Run the shipped scheduler against the copied fixture app, never the portal database.
    $historyCount=(int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    for($syncAttempt=0;$syncAttempt<2;$syncAttempt++){
        $syncProcess=proc_open([PHP_BINARY,$app.'/php/sync-academic-terms.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$syncPipes,$app,$environment,['bypass_shell'=>true]);
        fclose($syncPipes[0]);$syncOutput=stream_get_contents($syncPipes[1]);fclose($syncPipes[1]);$syncErrors=stream_get_contents($syncPipes[2]);fclose($syncPipes[2]);
        httpCheck(proc_close($syncProcess)===0 && $syncErrors==='' && str_contains($syncOutput,'Asia/Manila'),'CLI sync failed against fixture database');
    }
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===$historyCount,'Repeated CLI sync appended enrollment history');
    file_put_contents($app.'/db.php','<?php die("fixture database unavailable");');
    $syncFailure=proc_open([PHP_BINARY,$app.'/php/sync-academic-terms.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$failurePipes,$app,$environment,['bypass_shell'=>true]);
    fclose($failurePipes[0]);stream_get_contents($failurePipes[1]);fclose($failurePipes[1]);stream_get_contents($failurePipes[2]);fclose($failurePipes[2]);
    httpCheck(proc_close($syncFailure)===1,'Scheduler reported success when db.php terminated on connection failure');
    echo "PASS: $checks academic-term/data/HTTP checks; disposable fixtures only; no real messages sent.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $pdo=null;if($created)$source->exec('DROP DATABASE '.$database);
    if(is_dir($temporary))removeSetupTree($temporary,$temporary);
}
