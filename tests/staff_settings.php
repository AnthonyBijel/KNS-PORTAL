<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
$source = $pdo;
$root = dirname(__DIR__);
$database = 'kns_settings_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/kns-settings-' . bin2hex(random_bytes(8));
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
        if (!isset($match[1])) preg_match('/data-csrf="([a-f0-9]+)"/', (string) $response['body'], $match);
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

require_once __DIR__ . '/../php/admin/staff-email-change.php';
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
    $staffPassword='Staff-fixture-password-123';
    foreach(['admin'=>'Administrator','registrar'=>'Registrar','osa'=>'OSA'] as $name=>$role)$adminInsert->execute(['settings.'.$name,password_hash($staffPassword,PASSWORD_DEFAULT),'Test '.$role,$role,'Fixture School',$name.'@example.invalid','09171234567']);
    $original=$pdo->query('SELECT * FROM admin_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $migration=file_get_contents($root.'/database/migrations/20261106_staff_settings.sql');
    $pdo->exec($migration);$pdo->exec($migration);
    httpCheck($pdo->query('SELECT * FROM admin_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$original,'Migration changed staff data');
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_account_settings')->fetchColumn()===3,'Migration duplicated settings');
    $ids=$pdo->query('SELECT username,id FROM admin_account')->fetchAll(PDO::FETCH_KEY_PAIR);
    $sessions=['admin'=>['admin_id'=>(int)$ids['settings.admin']],'registrar'=>['office_id'=>(int)$ids['settings.registrar'],'office_role'=>'Registrar'],'osa'=>['office_id'=>(int)$ids['settings.osa'],'office_role'=>'OSA']];
    $delivered=[];
    $deliver=static function($email,$subject,$message)use(&$delivered):void{$delivered[]=[$email,$subject,$message];};
    $settings=new KnsStaffSettings($pdo,$deliver);$emails=new KnsStaffEmailChange($pdo,$deliver);
    $reject=static function(callable $action,int $status,string $label):void{
        try{$action();}catch(KnsStaffSettingsError $error){httpCheck($error->status===$status,$label.' ('.$error->status.')');return;}
        throw new RuntimeException($label.' unexpectedly succeeded');
    };
    foreach($sessions as $name=>$session){
        $state=$settings->state($session);
        httpCheck($state['profile']['email']===$name.'@example.invalid' && $state['preferences']['theme']==='light','Wrong initial account settings');
        httpCheck(!str_contains(json_encode($state),'password_hash')&&!str_contains(json_encode($state),'code_hash'),'Settings exposed secret material');
    }
    $reject(fn()=>$settings->state([]),401,'Anonymous settings');
    $reject(fn()=>$settings->state(['student_id'=>1]),401,'Student settings');
    $reject(fn()=>$settings->state(['admin_id'=>(int)$ids['settings.osa']]),403,'Office escalated to administrator');
    foreach($sessions as $name=>$session){
        $settings->save($session,'profile',['full_name'=>'Updated '.$name]);
        $settings->save($session,'contacts',['phone_number'=>'09171234567','alternate_contact'=>'+63 922 123 4567','address'=>"Fixture address\nSubic, Zambales"]);
        httpCheck($settings->state($session)['profile']['full_name']==='Updated '.$name,'Name saved to wrong account');
        httpCheck($settings->state($session)['preferences']['alternate_contact']==='+63 922 123 4567','Alternate contact not saved');
        httpCheck($settings->state($session)['profile']['address']==="Fixture address\nSubic, Zambales",'Address not saved to signed-in account');
    }
    $settings->save($sessions['admin'],'profile',['full_name'=>'Updated admin','school_name'=>'Updated school']);
    httpCheck($settings->state($sessions['admin'])['profile']['school_name']==='Updated school','Administrator school-name edit failed');
    httpCheck($settings->state($sessions['registrar'])['profile']['school_name']==='Fixture School','School edit changed another office');
    $reject(fn()=>$settings->save($sessions['osa'],'profile',['full_name'=>'OSA','school_name'=>'Forbidden']),403,'OSA edited school name');
    $reject(fn()=>$settings->save($sessions['registrar'],'profile',['full_name'=>'Registrar','school_name'=>'Forbidden']),403,'Registrar edited school name');
    $reject(fn()=>$settings->save($sessions['admin'],'contacts',['school_name'=>'Wrong action','phone_number'=>'09171234567']),403,'School edit outside Profile Settings');
    $settings->save($sessions['admin'],'profile',['full_name'=>'Updated admin','school_name'=>'Fixture School']);
    $settings->save($sessions['registrar'],'appearance',['theme'=>'dark']);
    $settings->save($sessions['osa'],'language',['language'=>'fil']);
    httpCheck($settings->state($sessions['admin'])['preferences']['theme']==='light' && $settings->state($sessions['registrar'])['preferences']['theme']==='dark','Theme leaked between accounts');
    httpCheck($settings->state($sessions['admin'])['preferences']['language']==='en' && $settings->state($sessions['osa'])['preferences']['language']==='fil','Language leaked between accounts');
    $reject(fn()=>$settings->save($sessions['admin'],'appearance',['theme'=>'invalid']),422,'Invalid theme');
    $reject(fn()=>$settings->save($sessions['admin'],'language',['language'=>['fil']]),422,'Array language');
    $reject(fn()=>$settings->save($sessions['admin'],'profile',['full_name'=>'']),422,'Blank name');
    $reject(fn()=>$settings->save($sessions['admin'],'contacts',['phone_number'=>'not a phone']),422,'Invalid contact');
    $reject(fn()=>$settings->save($sessions['admin'],'notifications',['notify_requests'=>'true']),422,'Invalid preferences');
    $off=['notify_announcements'=>'0','notify_requests'=>'0','notify_activity'=>'0','notify_email'=>'0'];
    $settings->save($sessions['admin'],'notifications',$off);$count=count($delivered);
    $settings->save($sessions['admin'],'appearance',['theme'=>'dark']);
    httpCheck(count($delivered)===$count,'Disabled optional email was sent');
    $newPassword='New-fixture-password-456';
    $security=['current_password'=>'wrong','new_password'=>$newPassword,'password_confirmation'=>$newPassword];
    for($i=0;$i<5;$i++)$reject(fn()=>$settings->save($sessions['admin'],'security',$security),422,'Wrong current password');
    $reject(fn()=>$settings->save($sessions['admin'],'security',array_replace($security,['current_password'=>$staffPassword])),429,'Password attempts not throttled');
    $pdo->exec('UPDATE staff_account_settings SET auth_failures=0,auth_locked_until=NULL');
    $security['current_password']=$staffPassword;
    $reject(fn()=>$settings->save($sessions['admin'],'security',array_replace($security,['password_confirmation'=>'mismatch'])),422,'Password confirmation');
    $reject(fn()=>$settings->save($sessions['admin'],'security',['current_password'=>$staffPassword,'new_password'=>'short','password_confirmation'=>'short']),422,'Short password');
    $settings->save($sessions['admin'],'security',$security);
    $hashes=$pdo->query('SELECT username,password_hash FROM admin_account')->fetchAll(PDO::FETCH_KEY_PAIR);
    httpCheck(password_verify($newPassword,$hashes['settings.admin'])&&!password_verify($staffPassword,$hashes['settings.admin']),'Password hash not changed securely');
    httpCheck(password_verify($staffPassword,$hashes['settings.osa']),'Password change affected another office');
    $send=['new_email'=>'verified@example.invalid','email_confirmation'=>'verified@example.invalid'];
    $emails->send($sessions['admin'],$send);$mail=$delivered[array_key_last($delivered)];preg_match('/code is ([0-9]{6})/',$mail[2],$match);$otp=$match[1];
    httpCheck($settings->state($sessions['admin'])['profile']['email']==='admin@example.invalid','Email changed before verification');
    httpCheck($mail[0]==='verified@example.invalid' && !str_contains(json_encode($settings->state($sessions['admin'])),$otp),'Code leaked from state');
    $reject(fn()=>$emails->send($sessions['admin'],$send),429,'Email resend not throttled');
    $reject(fn()=>$emails->verify($sessions['osa'],['verification_code'=>$otp]),410,'Another office reused a code');
    $reject(fn()=>$emails->verify($sessions['admin'],['verification_code'=>$otp==='111111'?'222222':'111111']),422,'Wrong OTP accepted');
    $emails->verify($sessions['admin'],['verification_code'=>$otp]);
    httpCheck($settings->state($sessions['admin'])['profile']['email']==='verified@example.invalid','Verified email not saved');
    httpCheck($settings->state($sessions['osa'])['profile']['email']==='osa@example.invalid','Email changed another office');
    $reject(fn()=>$emails->verify($sessions['admin'],['verification_code'=>$otp]),410,'OTP replay');
    $reject(fn()=>$emails->send($sessions['admin'],['new_email'=>'osa@example.invalid','email_confirmation'=>'osa@example.invalid']),409,'Duplicate email');
    $reject(fn()=>$emails->send($sessions['admin'],['new_email'=>'test@example.invalid','email_confirmation'=>'different@example.invalid']),422,'Email confirmation mismatch');
    $emails->send($sessions['admin'],['new_email'=>'expired@example.invalid','email_confirmation'=>'expired@example.invalid']);
    $pdo->exec("UPDATE staff_email_changes SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)");
    $reject(fn()=>$emails->verify($sessions['admin'],['verification_code'=>'123456']),410,'Expired OTP');
    $pdo->exec('DELETE FROM staff_email_changes');
    $emails->send($sessions['admin'],['new_email'=>'locked@example.invalid','email_confirmation'=>'locked@example.invalid']);
    $latest=$delivered[array_key_last($delivered)];preg_match('/code is ([0-9]{6})/',$latest[2],$m);$wrong=$m[1]==='111111'?'222222':'111111';
    for($i=1;$i<=5;$i++)$reject(fn()=>$emails->verify($sessions['admin'],['verification_code'=>$wrong]),$i===5?429:422,'OTP attempt limit');
    $pdo->exec('DELETE FROM staff_email_changes');
    $failed=new KnsStaffEmailChange($pdo,static function():void{throw new RuntimeException('Fixture delivery failed');});
    $reject(fn()=>$failed->send($sessions['admin'],['new_email'=>'failed@example.invalid','email_confirmation'=>'failed@example.invalid']),503,'Failed email reported success');
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_email_changes')->fetchColumn()===0 && $settings->state($sessions['admin'])['profile']['email']==='verified@example.invalid','Failed delivery changed email or kept challenge');
    $pdo->exec("UPDATE staff_account_settings SET notify_email=0,notify_announcements=1,notify_requests=1,notify_activity=1,theme='light',language='en',auth_failures=0,auth_locked_until=NULL");
    $pdo->exec("UPDATE admin_account SET admin_name=CASE role WHEN 'Administrator' THEN 'Test Administrator' WHEN 'Registrar' THEN 'Test Registrar' ELSE 'Test OSA' END");

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
    $endpoint='/php/admin/staff-settings.php';$clients=[];
    $anonymous=new SetupBrowser($base);
    httpCheck($anonymous->request($endpoint,null,'application/json')['status']===401,'Anonymous API access');
    foreach($sessions as $name=>$session){
        $client=new SetupBrowser($base);$client->token();
        $login=$client->request('/php/auth/admin_login.php',['csrf'=>$client->csrf,'student_number'=>'settings.'.$name,'password'=>$name==='admin'?$newPassword:$staffPassword]);
        httpCheck(str_ends_with($login['location'],'/admin/dashboard.php'),'Staff login failed: '.$name);
        $page=$client->token('/admin/profile.php');
        httpCheck(str_contains($page['body'],'data-settings-gear') && substr_count($page['body'],'role="menuitem"')===7,'Profile settings menu missing: '.$name);
        httpCheck(!str_contains(substr($page['body'],strpos($page['body'],'class="ks-menu"'),2000),'Sign out'),'Unexpected sign-out menu item');
        $data=json_decode($client->request($endpoint,null,'application/json')['body'],true);
        httpCheck($data['ok'] && $data['state']['profile']['role']===($name==='admin'?'Administrator':ucfirst($name==='osa'?'OSA':$name)),'API identity mismatch');
        httpCheck($client->request($endpoint.'?account_id=999',null,'application/json')['status']===403,'IDOR GET allowed');
        $post=['csrf'=>$client->csrf,'action'=>'profile','full_name'=>'HTTP '.$name];
        httpCheck($client->request($endpoint,array_replace($post,['csrf'=>'bad']),'application/json')['status']===403,'Missing CSRF');
        httpCheck($client->request($endpoint,array_replace($post,['id'=>999]),'application/json')['status']===403,'IDOR POST allowed');
        httpCheck($client->request($endpoint,array_replace($post,['role'=>'Administrator']),'application/json')['status']===403,'Role escalation allowed');
        httpCheck($client->request($endpoint,$post,'application/json')['status']===200,'Own profile save failed');
        $current=$settings->state($session);
        httpCheck(!str_contains($page['body'],'id="admin-profile-form"') && !str_contains($page['body'],'class="admin-profile-actions"'),'Legacy editing controls remain');
        $profileDocument=new DOMDocument();
        $profileDocument->loadHTML($page['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach(['name','school','email','contact','address'] as $field) {
            httpCheck($profileDocument->getElementById('admin-profile-'.$field)?->hasAttribute('readonly')===true,'Profile field is editable: '.$field);
        }
        $settings->save($session,'appearance',['theme'=>'dark']);
        $settings->save($session,'language',['language'=>'fil']);
        $shell=$client->request('/admin/dashboard.php')['body'];
        httpCheck(str_contains($shell,'<html lang="fil" data-staff-portal data-staff-theme="dark" data-staff-language="fil">'),'Saved preferences not bootstrapped across staff portal');
        httpCheck(str_contains($shell,'staff-portal.css') && str_contains($shell,'staff-translations.js'),'Global presentation assets missing');
        $settings->save($session,'appearance',['theme'=>'light']);
        $settings->save($session,'language',['language'=>'en']);
        $oldForm=['csrf'=>$client->csrf,'admin_name'=>'HTTP '.$name,'school_name'=>'Fixture School','school_email'=>'unverified-'.$name.'@example.invalid','school_contact'=>'09171234567','school_address'=>'Fixture address'];
        httpCheck($client->request('/admin/profile.php',$oldForm,'application/json')['status']===405,'Legacy profile update endpoint must be read-only');
        httpCheck($settings->state($session)['profile']['email']===$current['profile']['email'],'Legacy email guard failed');
        $image=$temporary.'/'.$name.'.png';$im=imagecreatetruecolor(8,8);imagepng($im,$image);imagedestroy($im);
        $photo=$client->request('/php/admin/upload_profile_photo.php',['csrf'=>$client->csrf,'profile_photo'=>new CURLFile($image,'image/png','profile.png')],'application/json');
        httpCheck($photo['status']===200 && str_starts_with(json_decode($photo['body'],true)['photo_path'],'uploads/admins/'),'Existing photo upload failed');
        $json=$client->request($endpoint,['csrf'=>$client->csrf,'action'=>'send_email','new_email'=>'delivery-'.$name.'@example.invalid','email_confirmation'=>'delivery-'.$name.'@example.invalid'],'application/json');
        httpCheck($json['status']===503 && !json_decode($json['body'],true)['ok'],'SMTP failure produced fake success');
        httpCheck($settings->state($session)['profile']['email']===$current['profile']['email'],'Failed HTTP delivery changed email');
        $challenge=$pdo->prepare('INSERT INTO staff_email_changes(admin_id,new_email,previous_email,code_hash,expires_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE))');
        $challenge->execute([$name==='admin'?$ids['settings.admin']:$ids['settings.'.$name],'http-verified-'.$name.'@example.invalid',$current['profile']['email'],password_hash('654321',PASSWORD_DEFAULT)]);
        $verified=$client->request($endpoint,['csrf'=>$client->csrf,'action'=>'verify_email','verification_code'=>'654321'],'application/json');
        httpCheck($verified['status']===200 && json_decode($verified['body'],true)['state']['profile']['email']==='http-verified-'.$name.'@example.invalid','HTTP verified email failed');
        httpCheck($client->request($endpoint,['csrf'=>$client->csrf,'action'=>'verify_email','verification_code'=>'654321'],'application/json')['status']===410,'HTTP OTP replay');
        $clients[$name]=$client;
    }
    // The existing staff notification feed must respect each source preference and office assignment.
    $pdo->prepare("INSERT INTO students(student_number,password_hash,first_name,last_name,course,year_level,section,status,requires_password_change) VALUES('SETTINGS-STUDENT',?,'Fixture','Student','BSCS',1,'A','Active',0)")->execute([password_hash('Fixture-student-password',PASSWORD_DEFAULT)]);
    $studentId=(int)$pdo->lastInsertId();
    $insert=$pdo->prepare("INSERT INTO requests(reference_no,student_id,request_type,academic_year,semester,purpose,submission_token,assigned_office) VALUES(?,?,'Certificate','2026-2027','1st Semester','Fixture purpose',?,?)");
    foreach(['Admin','Registrar','OSA'] as $office)$insert->execute(['SETTINGS-'.$office,$studentId,bin2hex(random_bytes(18)),$office]);
    $pdo->exec("INSERT INTO announcements(announcement_type,title,description) VALUES('General','Fixture notice','Fixture system announcement')");
    $announcementId=(int)$pdo->lastInsertId();
    $feed='/php/admin/notifications.php';
    foreach($clients as $name=>$client){
        $flags=['notify_announcements'=>'0','notify_requests'=>'1','notify_activity'=>'0','notify_email'=>'0'];
        $client->request($endpoint,$flags+['csrf'=>$client->csrf,'action'=>'notifications'],'application/json');
        $response=$client->request($feed,null,'application/json');$data=json_decode($response['body'],true);
        httpCheck($response['status']===200 && count($data['notifications'])===($name==='admin'?3:1),'Notification office scope/preferences failed: '.$response['body']);
        $flags=['notify_announcements'=>'1','notify_requests'=>'0','notify_activity'=>'0','notify_email'=>'0'];
        $client->request($endpoint,$flags+['csrf'=>$client->csrf,'action'=>'notifications'],'application/json');
        $data=json_decode($client->request($feed,null,'application/json')['body'],true);
        httpCheck(count($data['notifications'])===1 && $data['notifications'][0]['type']==='announcement','Announcement preference not honored');
        httpCheck($client->request($feed,['csrf'=>$client->csrf,'action'=>'read','type'=>'announcement','source_id'=>$announcementId],'application/json')['status']===200,'Announcement read receipt failed');
        $flags=['notify_announcements'=>'0','notify_requests'=>'0','notify_activity'=>'1','notify_email'=>'0'];
        $client->request($endpoint,$flags+['csrf'=>$client->csrf,'action'=>'notifications'],'application/json');
        $data=json_decode($client->request($feed,null,'application/json')['body'],true);
        httpCheck($data['total']>0 && count(array_filter($data['notifications'],fn($row)=>$row['type']!=='activity'))===0,'Activity preference not honored');
        $other=(int)$pdo->query('SELECT id FROM staff_account_activity WHERE admin_id<>'.(int)$ids['settings.'.$name].' LIMIT 1')->fetchColumn();
        httpCheck($client->request($feed,['csrf'=>$client->csrf,'action'=>'read','type'=>'activity','source_id'=>$other],'application/json')['status']===404,'Read another account activity');
    }
    $student=new SetupBrowser($base);$student->login('SETTINGS-STUDENT','Fixture-student-password');
    httpCheck(in_array($student->request($endpoint,null,'application/json')['status'],[401,403],true),'Student accessed staff settings');
    $pdo->prepare('UPDATE admin_account SET is_active=0 WHERE id=?')->execute([$ids['settings.osa']]);
    httpCheck($clients['osa']->request($endpoint,null,'application/json')['status']===401,'Inactive staff settings allowed');
    $pdo->prepare('UPDATE admin_account SET is_active=1 WHERE id=?')->execute([$ids['settings.osa']]);
    $pdo->exec("UPDATE staff_account_settings SET theme='light',language='en',notify_announcements=1,notify_requests=1,notify_activity=1,notify_email=0");
    // A delivered-code fixture exercises verification UI without sending real mail.
    $pdo->prepare('INSERT INTO staff_email_changes(admin_id,new_email,previous_email,code_hash,expires_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE))')->execute([$ids['settings.admin'],'browser-verified@example.invalid','http-verified-admin@example.invalid',password_hash('987654',PASSWORD_DEFAULT)]);
    if(in_array('--browser',$argv,true)){
        $artifacts=getenv('KNS_TEST_ARTIFACTS')?:sys_get_temp_dir().'/kns-staff-settings-review';
        if(!is_dir($artifacts))mkdir($artifacts,0700,true);
        $config=$temporary.'/browser.json';
        file_put_contents($config,json_encode(['base'=>$base,'cookies'=>array_map(fn($client)=>array_values($client->cookies),$clients),'temporary'=>$temporary,'artifacts'=>$artifacts,'photo'=>$temporary.'/admin.png'],JSON_THROW_ON_ERROR));
        $browser=proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',$root.'/tests/staff_settings_browser.ps1','-ConfigPath',$config],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,null,['bypass_shell'=>true]);fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($browser)!==0)throw new RuntimeException('Browser checks failed: '.$output.$errors);
        echo $output;
    }
    echo "PASS: $checks staff settings/migration/security/email/HTTP checks; disposable fixtures only, no real messages.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $pdo=null;if($created)$source->exec('DROP DATABASE '.$database);
    if(is_dir($temporary))removeSetupTree($temporary,$temporary);
}

