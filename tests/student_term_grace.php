<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
$source=$pdo;$database='kns_grace_test_'.bin2hex(random_bytes(6));$checks=0;
function graceCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function graceRow(PDO $pdo,string $number):array{$q=$pdo->prepare('SELECT * FROM students WHERE student_number=?');$q->execute([$number]);return $q->fetch(PDO::FETCH_ASSOC)?:[];}
function graceSeed(PDO $pdo,string $number,string $status='Active',?string $email='student@example.invalid'):int{
    $q=$pdo->prepare("INSERT INTO students(student_number,password_hash,first_name,last_name,course,year_level,section,status,email,contact_number,address,birth_date,guardian_name,guardian_contact,profile_photo)
        VALUES(?,?,'Grace','Student','BSCS',1,'TEST',?,?,'09171234567','Private address','2000-01-01','Guardian','09171234567',?)");
    $q->execute([$number,password_hash('Fixture-old-password',PASSWORD_DEFAULT),$status,$email,'uploads/students/student_'.bin2hex(random_bytes(20)).'.png']);return(int)$pdo->lastInsertId();
}
$source->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
    $pdo=new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4",$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    // Schema only, in FK dependency order. FK checks remain enabled.
    $pending=array_fill_keys($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),true);
    while($pending){
        $progress=false;
        foreach(array_keys($pending)as$table){
            $refs=$source->prepare('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $refs->execute([$table]);$blocked=false;foreach($refs->fetchAll(PDO::FETCH_COLUMN)as$ref)if($ref!==$table&&isset($pending[$ref]))$blocked=true;
            if($blocked)continue;
            $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$table).chr(96);
            $pdo->exec($source->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1]);unset($pending[$table]);$progress=true;
        }
        if(!$progress)throw new RuntimeException('Cyclic fixture schema dependency');
    }
    foreach(['20261105_academic_term_management.sql','20261105_student_term_activation.sql','20261106_student_term_grace.sql']as$file){$sql=file_get_contents(__DIR__.'/../database/migrations/'.$file);$pdo->exec($sql);$pdo->exec($sql);}
    $today=new DateTimeImmutable('today',knsAcademicTermZone());$year=(int)$today->format('Y');$ay="$year-".($year+1);$calendar=new KnsAcademicTerms($pdo);
    $prior=$calendar->schedule(['academic_year'=>($year-1)."-$year",'semester'=>'1st Semester','start_date'=>$today->modify('-100 days')->format('Y-m-d'),'official_end_date'=>$today->modify('-1 day')->format('Y-m-d')],1);
    $calendar->schedule(['academic_year'=>$ay,'semester'=>'1st Semester','start_date'=>$today->format('Y-m-d'),'official_end_date'=>$today->modify('+30 days')->format('Y-m-d')],1);
    $future=$calendar->schedule(['academic_year'=>$ay,'semester'=>'2nd Semester','start_date'=>$today->modify('+120 days')->format('Y-m-d'),'official_end_date'=>$today->modify('+180 days')->format('Y-m-d')],1);
    $pdo->prepare("INSERT INTO admin_account(username,password_hash,admin_name,role,school_name,school_email,school_contact,school_address) VALUES(?,?,?,'Administrator',?,?,?,?)")->execute(['fixture.admin',password_hash('Fixture-admin-password',PASSWORD_DEFAULT),'Fixture Admin','Fixture School','school@example.invalid','0123456789','School address']);
    foreach([['2025-11-30 00:00:00','2026-02-28 00:00:00'],['2023-11-30 12:34:56','2024-02-29 12:34:56'],['2026-01-31 00:00:00','2026-04-30 00:00:00'],['2026-12-31 00:00:00','2027-03-31 00:00:00']]as[$from,$to])graceCheck(knsTermGraceExpiry(new DateTimeImmutable($from,knsAcademicTermZone()))->format('Y-m-d H:i:s')===$to,'Month clamp: '.$from);
    $id=graceSeed($pdo,'GRACE-NONE');knsSyncStudentTerm($pdo,$id,$today->modify('-1 day')->setTime(23,59,59));graceCheck(graceRow($pdo,'GRACE-NONE')['status']==='Active','Ended during official end date');
    $pdo->beginTransaction();knsSyncStudentTerm($pdo,$id,$today);graceCheck(knsProcessTermEmails($pdo,$id,static function(){throw new RuntimeException('Must not send');})===0,'Sent before commit');$pdo->rollBack();
    graceCheck(graceRow($pdo,'GRACE-NONE')['status']==='Active'&&(int)$pdo->query('SELECT COUNT(*) FROM student_term_emails')->fetchColumn()===0,'Rollback leaked state/email');
    knsSyncStudentTerm($pdo,$id,$today);$row=graceRow($pdo,'GRACE-NONE');$expiry=knsTermGraceExpiry($today);
    graceCheck($row['status']==='Inactive'&&(int)$row['term_auto_inactive']===1,'Missing enrollment bypassed inactivation');
    graceCheck($row['term_inactivated_at']===knsTermUtc($today)&&$row['term_expires_at']===knsTermUtc($expiry),'Wrong recorded boundaries');
    graceCheck(knsStudentMayAccess($row,$expiry->modify('-1 second'))&&!knsStudentMayAccess($row,$expiry),'Exact deadline not enforced');
    graceCheck(knsStudentMayAccess($row,$today->setTimezone(new DateTimeZone('UTC'))),'Host timezone changed access');
    knsSyncTermAccounts($pdo,$today);graceCheck((int)$pdo->query('SELECT COUNT(*) FROM student_term_emails')->fetchColumn()===1,'Duplicate warning');
    $linked=graceSeed($pdo,'GRACE-UNLINKED');knsEnrollmentSnapshot($pdo,$linked,graceRow($pdo,'GRACE-UNLINKED'));knsSyncStudentTerm($pdo,$linked,$today);graceCheck(knsStudentMayAccess(graceRow($pdo,'GRACE-UNLINKED')),'Unlinked enrollment missed grace');
    foreach(['Inactive','Graduated']as$state){$sid=graceSeed($pdo,'MANUAL-'.$state,$state);knsSyncStudentTerm($pdo,$sid,$today);graceCheck(!knsStudentMayAccess(graceRow($pdo,'MANUAL-'.$state))&&graceRow($pdo,'MANUAL-'.$state)['term_inactivated_at']===null,'Manual status received grace');}
    $body='';$messageId='';$calls=0;knsProcessTermEmails($pdo,$id,function()use(&$calls){$calls++;throw new RuntimeException('PRIVATE SMTP DETAIL');});
    $job=$pdo->query('SELECT * FROM student_term_emails WHERE student_id='.$id)->fetch(PDO::FETCH_ASSOC);graceCheck($job['delivery_status']==='failed'&&$job['failure_code']==='delivery_failed'&&!str_contains(json_encode($job),'PRIVATE'),'Delivery diagnostics leaked');
    $pdo->exec('UPDATE student_term_emails SET next_attempt_at=NULL WHERE student_id='.$id);
    $send=function($channel,$to,$subject,$message,$mid)use(&$calls,&$body,&$messageId,$pdo){graceCheck(!$pdo->inTransaction(),'SMTP held transaction');$calls++;$body=$message;$messageId=$mid;};
    knsProcessTermEmails($pdo,$id,$send);knsProcessTermEmails($pdo,$id,$send);graceCheck($calls===2&&$pdo->query('SELECT delivery_status FROM student_term_emails WHERE student_id='.$id)->fetchColumn()==='sent','Retry/duplicate guard failed');
    foreach(['Grace Student',$prior['academic_year'],$prior['semester'],$prior['official_end_date'],knsTermLocalDate($row['term_inactivated_at']),knsTermLocalDate($row['term_expires_at']),'Asia/Manila','full access','existing student_number','already re-enrolled','school@example.invalid','Historical academic']as$piece)graceCheck(str_contains($body,$piece),'Warning missing '.$piece);
    graceCheck(!str_contains($body,'Fixture-old-password')&&!str_contains($body,$row['password_hash'])&&str_contains($messageId,'kns-term-'.$id.'-'.$prior['id']),'Email exposed credentials/unstable identity');
    $missing=graceSeed($pdo,'NO-EMAIL','Active',null);knsSyncStudentTerm($pdo,$missing,$today);graceCheck($pdo->query('SELECT failure_code FROM student_term_emails WHERE student_id='.$missing)->fetchColumn()==='no_valid_email','Missing email not reported');
    $pending=graceSeed($pdo,'FUTURE-GRACE');knsSyncStudentTerm($pdo,$pending,$today);$setup=new KnsStudentAccountSetup($pdo,static function(){});
    $values=['student_number'=>'FUTURE-GRACE','first_name'=>'Grace','last_name'=>'Student','academic_year'=>$ay,'semester'=>'2nd Semester','course'=>'BSIT','year_level'=>2,'section'=>'NEW','status'=>'Active'];
    $old=graceRow($pdo,'FUTURE-GRACE');$setup->saveImportedStudent($values);$waiting=graceRow($pdo,'FUTURE-GRACE');
    graceCheck($waiting['status']==='Inactive'&&$waiting['term_expires_at']===null&&knsStudentMayAccess($waiting,$expiry->modify('+1 day')),'Future enrollment failed to cancel expiry');
    graceCheck($waiting['password_hash']===$old['password_hash']&&$waiting['profile_photo']===$old['profile_photo'],'Future import changed credentials/photo');
    knsProcessTermEmails($pdo,$pending,$send);graceCheck(str_contains($body,'already recorded')&&str_contains($body,$future['start_date'])&&str_contains($body,'cancelled'),'Future notice misleading');
    knsSyncStudentTerm($pdo,$pending,new DateTimeImmutable($future['start_date'],knsAcademicTermZone()));graceCheck(graceRow($pdo,'FUTURE-GRACE')['status']==='Active'&&graceRow($pdo,'FUTURE-GRACE')['course']==='BSIT','Future activation failed');
    $setup->saveImportedStudent(array_replace($values,['student_number'=>'MANUAL-Inactive']));graceCheck(!knsStudentMayAccess(graceRow($pdo,'MANUAL-Inactive')),'Manual future account received early grace');
    $setup->saveImportedStudent(array_replace($values,['student_number'=>'MANUAL-Graduated','semester'=>'1st Semester']));graceCheck(graceRow($pdo,'MANUAL-Graduated')['status']==='Active','Graduated re-enrollment failed');
    $unchanged=graceRow($pdo,'GRACE-NONE');try{$setup->saveImportedStudent(['student_number'=>'GRACE-NONE','status'=>'Active']);throw new RuntimeException('Incomplete Active accepted');}catch(InvalidArgumentException $e){graceCheck(graceRow($pdo,'GRACE-NONE')===$unchanged,'Rejected import changed account');}
    $setup->saveImportedStudent(['student_number'=>'GRACE-NONE','status'=>'','section'=>'TEST']);graceCheck(graceRow($pdo,'GRACE-NONE')['term_expires_at']===$unchanged['term_expires_at'],'Blank status cancelled expiry');
    // Actual history tables/FKs remain enabled during scrubbing.
    knsEnrollmentSnapshot($pdo,$id,graceRow($pdo,'GRACE-NONE'));
    $pdo->prepare('INSERT INTO subject_grades(student_number,academic_year,semester,subject_code,subject_name,overall_grade) VALUES(?,?,?,?,?,90)')->execute(['GRACE-NONE',$prior['academic_year'],'1st Semester','HIST','History']);
    $pdo->prepare("INSERT INTO grade_assessments(student_number,academic_year,semester,grading_period,subject_code,subject_name,assessment_type,assessment_name,score,max_score) VALUES(?,?,?,'Prelim','HIST','History','quiz','Saved quiz',9,10)")->execute(['GRACE-NONE',$prior['academic_year'],'1st Semester']);
    $pdo->prepare("INSERT INTO requests(reference_no,student_id,request_type,academic_year,semester,purpose,submission_token) VALUES(?,?,'Grade Record Request',?,'1st Semester','Historical request',?)")->execute(['FIXTURE-1',$id,$prior['academic_year'],bin2hex(random_bytes(18))]);$request=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO request_messages(request_id,sender_role,sender_student_id,body,client_token) VALUES(?,'student',?,'Historical message',?)")->execute([$request,$id,bin2hex(random_bytes(18))]);$message=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO request_attachments(request_id,message_id,original_name,storage_key,mime_type,byte_size) VALUES(?,?,'history.pdf',?,'application/pdf',100)")->execute([$request,$message,bin2hex(random_bytes(32))]);
    $pdo->prepare("INSERT INTO password_reset_tokens(student_id,token_hash,channel,expires_at) VALUES(?,?,'email',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")->execute([$id,password_hash('123456',PASSWORD_DEFAULT)]);
    $history=[];foreach(['student_enrollments','subject_grades','grade_assessments','requests','request_messages','request_attachments']as$table)$history[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    knsSyncStudentTerm($pdo,$id,$expiry);$archived=graceRow($pdo,'GRACE-NONE');graceCheck((int)$archived['id']===$id&&$archived['student_number']==='GRACE-NONE'&&!empty($archived['archived_at'])&&!knsStudentMayAccess($archived),'Archived mapping/login wrong');
    foreach(['password_hash','middle_name','suffix','academic_year','semester','email','contact_number','address','birth_date','guardian_name','guardian_contact','profile_photo','temporary_password_expires_at']as$field)graceCheck($archived[$field]===null,'Archive retained '.$field);
    foreach(['first_name','last_name','course','section']as$field)graceCheck($archived[$field]==='','Archive retained '.$field);
    foreach($history as$table=>$before)graceCheck($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Expiry changed '.$table);
    graceCheck((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE student_id='.$id)->fetchColumn()===0,'Expired reset tokens retained');
    graceCheck((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'FK checks disabled');
    try{$setup->saveImportedStudent(['student_number'=>'GRACE-NONE','email'=>'restore@example.invalid']);throw new RuntimeException('Archived profile restore accepted');}catch(InvalidArgumentException $e){graceCheck(graceRow($pdo,'GRACE-NONE')===$archived,'Profile update restored archive');}
    $setup->saveImportedStudent(array_replace($values,['student_number'=>'GRACE-NONE','email'=>'restored@example.invalid']));
    graceCheck(!knsStudentMayAccess(graceRow($pdo,'GRACE-NONE')),'Archived identity received access before its new future term');
    $setup->saveImportedStudent(array_replace($values,['student_number'=>'GRACE-NONE','semester'=>'1st Semester','email'=>'restored@example.invalid']));$restored=graceRow($pdo,'GRACE-NONE');graceCheck((int)$restored['id']===$id&&$restored['archived_at']===null&&$restored['status']==='Active'&&!empty($restored['password_hash']),'Archived identity not reused');
    $uncertain=graceSeed($pdo,'UNCERTAIN');knsSyncStudentTerm($pdo,$uncertain,$today);knsProcessTermEmails($pdo,$uncertain,static function(){throw new KnsMailDeliveryUncertain('Not logged');});$count=0;knsProcessTermEmails($pdo,$uncertain,function()use(&$count){$count++;});graceCheck($count===0&&$pdo->query('SELECT delivery_status FROM student_term_emails WHERE student_id='.$uncertain)->fetchColumn()==='uncertain','Uncertain SMTP blindly retried');
    $stale=graceSeed($pdo,'STALE-WORKER');knsSyncStudentTerm($pdo,$stale,$today);$pdo->exec("UPDATE student_term_emails SET delivery_status='sending',claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE student_id=".$stale);knsProcessTermEmails($pdo,$stale,function()use(&$count){$count++;});graceCheck($count===0&&$pdo->query('SELECT delivery_status FROM student_term_emails WHERE student_id='.$stale)->fetchColumn()==='uncertain','Crashed worker retried');
    $otp='';$reset=new KnsPasswordResetService($pdo,function($channel,$to,$code)use(&$otp){$otp=$code;});$session=[];$reset->lookup('GRACE-UNLINKED',$session,'grace-test');$reset->request('GRACE-UNLINKED','email',$session,'grace-test');$reset->verify($otp,$session,'grace-test');$reset->reset('Fixture-grace-password','Fixture-grace-password',$session,'grace-test');graceCheck(password_verify('Fixture-grace-password',graceRow($pdo,'GRACE-UNLINKED')['password_hash']),'Grace password recovery blocked');
    echo "PASS: $checks grace/expiry/history/email checks; disposable database, FK checks enabled, fake delivery only.\n";
}finally{$pdo=null;$source->exec('DROP DATABASE '.$database);}
