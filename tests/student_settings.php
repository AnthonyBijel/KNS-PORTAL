<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
$source = $pdo;
$root = dirname(__DIR__);
$database = 'kns_student_settings_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . '/kns-student-settings-' . bin2hex(random_bytes(8));
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


require_once __DIR__ . '/../php/student/email-change.php';
try {
    mkdir($app,0700,true); mkdir($temporary.'/sessions');
    $source->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true;
    $dsn="mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo=new PDO($dsn,$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pending=array_fill_keys($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),true);
    while($pending){
        $progress=false;
        foreach(array_keys($pending) as $table){
            $refs=$source->prepare('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $refs->execute([$table]); $blocked=false;
            foreach($refs->fetchAll(PDO::FETCH_COLUMN) as $ref) if($ref!==$table&&isset($pending[$ref])) $blocked=true;
            if($blocked) continue;
            $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$table).chr(96);
            $pdo->exec($source->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1]);
            unset($pending[$table]); $progress=true;
        }
        if(!$progress) throw new RuntimeException('Cyclic fixture schema dependency');
    }
    $initialPassword='Student-fixture-password-123';
    $changedPassword='Student-changed-password-456';
    $insert=$pdo->prepare('INSERT INTO students(student_number,password_hash,first_name,last_name,course,year_level,section,status,email,contact_number,requires_password_change) VALUES(?,?,?,?,?,1,?,?,?, ?,0)');
    foreach(['alpha','beta'] as $name) $insert->execute(['SETTINGS-'.strtoupper($name),password_hash($initialPassword,PASSWORD_DEFAULT),'Fixture',ucfirst($name),'BSCS','TEST','Active',$name.'@example.invalid','09171234567']);
    $ids=$pdo->query('SELECT student_number,id FROM students')->fetchAll(PDO::FETCH_KEY_PAIR);
    $alpha=(int)$ids['SETTINGS-ALPHA']; $beta=(int)$ids['SETTINGS-BETA'];
    $sessions=['alpha'=>['student_id'=>$alpha],'beta'=>['student_id'=>$beta]];
    $before=$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $migration=file_get_contents($root.'/database/migrations/20261107_student_settings.sql');
    $pdo->exec($migration); $pdo->exec($migration);
    httpCheck($pdo->query('SELECT * FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Migration changed student records');
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM student_account_settings')->fetchColumn()===2,'Migration duplicated settings');
    $pdo->exec('UPDATE student_account_settings SET notify_email=0');
    $delivered=[];
    $deliver=static function($email,$subject,$message)use(&$delivered){$delivered[]=[$email,$subject,$message];};
    $settings=new KnsStudentSettings($pdo,$deliver);
    $emails=new KnsStudentEmailChange($pdo,$deliver);
    $reject=static function(callable $action,int $status,string $label){
        try{$action();}catch(KnsStudentSettingsError $error){httpCheck($error->status===$status,$label.' ('.$error->status.')');return;}
        throw new RuntimeException($label.' was accepted');
    };
    foreach([[],['admin_id'=>1],['office_id'=>1],['student_id'=>0],['student_id'=>(string)$alpha],['student_id'=>$alpha,'admin_id'=>1]] as $invalid) $reject(fn()=>$settings->state($invalid),401,'Invalid student session');
    $state=$settings->state($sessions['alpha']);
    httpCheck($state['profile']['student_number']==='SETTINGS-ALPHA' && $state['profile']['role']==='Student','Wrong student state');
    httpCheck(!str_contains(json_encode($state),'password_hash')&&!str_contains(json_encode($state),'code_hash'),'Secret data exposed');
    $contacts=['phone_number'=>'09221234567','alternate_contact'=>'+639331234567','address'=>"Fixture address\nSubic"];
    $settings->save($sessions['alpha'],'contacts',$contacts);
    $row=$pdo->query('SELECT * FROM students WHERE id='.$alpha)->fetch(PDO::FETCH_ASSOC);
    httpCheck($row['contact_number']===$contacts['phone_number']&&$row['address']===$contacts['address'],'Contact save failed');
    httpCheck($settings->state($sessions['alpha'])['preferences']['alternate_contact']===$contacts['alternate_contact'],'Alternate contact not saved');
    httpCheck($pdo->query('SELECT contact_number FROM students WHERE id='.$beta)->fetchColumn()==='09171234567','Changed another student');
    $reject(fn()=>$settings->save($sessions['alpha'],'contacts',array_replace($contacts,['phone_number'=>'broken'])),422,'Invalid phone');
    $reject(fn()=>$settings->save($sessions['alpha'],'contacts',array_replace($contacts,['address'=>str_repeat('x',256)])),422,'Oversize address');
    $reject(fn()=>$settings->save($sessions['alpha'],'profile',['full_name'=>'Changed identity']),422,'Identity edit');
    foreach([['appearance','theme','dark'],['language','language','fil']] as [$action,$field,$value]){
        $settings->save($sessions['alpha'],$action,[$field=>$value]);
        httpCheck($settings->state($sessions['alpha'])['preferences'][$field]===$value,'Preference not saved');
    }
    httpCheck($settings->state($sessions['beta'])['preferences']['theme']==='light','Theme leaked between students');
    $reject(fn()=>$settings->save($sessions['alpha'],'appearance',['theme'=>'unknown']),422,'Invalid theme');
    $reject(fn()=>$settings->save($sessions['alpha'],'language',['language'=>['fil']]),422,'Invalid language');
    $security=['current_password'=>$initialPassword,'new_password'=>$changedPassword,'password_confirmation'=>$changedPassword];
    $reject(fn()=>$settings->save($sessions['alpha'],'security',array_replace($security,['new_password'=>'short','password_confirmation'=>'short'])),422,'Short password');
    $reject(fn()=>$settings->save($sessions['alpha'],'security',array_replace($security,['password_confirmation'=>'Different-long-password'])),422,'Password mismatch');
    for($attempt=0;$attempt<5;$attempt++) $reject(fn()=>$settings->save($sessions['alpha'],'security',array_replace($security,['current_password'=>'wrong'])),422,'Wrong current password');
    $reject(fn()=>$settings->save($sessions['alpha'],'security',$security),429,'Password attempt lock');
    $pdo->prepare('UPDATE student_account_settings SET auth_locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE student_id=?')->execute([$alpha]);
    $pdo->prepare("INSERT INTO password_reset_tokens(student_id,token_hash,channel,expires_at) VALUES(?,?,'email',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->execute([$alpha,password_hash('123456',PASSWORD_DEFAULT)]);
    $settings->save($sessions['alpha'],'security',$security);
    httpCheck(password_verify($changedPassword,$pdo->query('SELECT password_hash FROM students WHERE id='.$alpha)->fetchColumn()),'New password not usable');
    httpCheck(password_verify($initialPassword,$pdo->query('SELECT password_hash FROM students WHERE id='.$beta)->fetchColumn()),'Changed another password');
    httpCheck((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE student_id='.$alpha)->fetchColumn()===0,'Recovery tokens survived password change');
    $reject(fn()=>$settings->save($sessions['alpha'],'security',['current_password'=>$changedPassword,'new_password'=>$changedPassword,'password_confirmation'=>$changedPassword]),422,'Reused password');
    $send=['new_email'=>'verified@example.invalid','email_confirmation'=>'verified@example.invalid'];
    $reject(fn()=>$emails->send($sessions['alpha'],array_replace($send,['new_email'=>'bad'])),422,'Invalid email');
    $reject(fn()=>$emails->send($sessions['alpha'],['new_email'=>'beta@example.invalid','email_confirmation'=>'beta@example.invalid']),409,'Duplicate email');
    $emails->send($sessions['alpha'],$send); $mail=$delivered[array_key_last($delivered)];
    preg_match('/code is ([0-9]{6})/',$mail[2],$match); $code=$match[1];
    httpCheck($settings->state($sessions['alpha'])['profile']['email']==='alpha@example.invalid','Email changed before verification');
    httpCheck(!str_contains(json_encode($settings->state($sessions['alpha'])),$code),'Verification code exposed');
    $reject(fn()=>$emails->send($sessions['alpha'],$send),429,'Email resend cooldown');
    $reject(fn()=>$emails->verify($sessions['beta'],['verification_code'=>$code]),410,'Another student reused verification');
    $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$code==='111111'?'222222':'111111']),422,'Wrong email code');
    $emails->verify($sessions['alpha'],['verification_code'=>$code]);
    httpCheck($settings->state($sessions['alpha'])['profile']['email']===$send['new_email'],'Verified email not saved');
    $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$code]),410,'Email code replay');
    $race=['new_email'=>'reserved@example.invalid','email_confirmation'=>'reserved@example.invalid'];
    $emails->send($sessions['alpha'],$race);
    $mail=$delivered[array_key_last($delivered)]; preg_match('/code is ([0-9]{6})/',$mail[2],$match);
    $pdo->prepare('UPDATE students SET email=? WHERE id=?')->execute([$race['new_email'],$beta]);
    $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$match[1]]),409,'Email claimed before verification');
    $pdo->prepare('UPDATE students SET email=? WHERE id=?')->execute(['beta@example.invalid',$beta]);
    $pdo->prepare('DELETE FROM student_email_changes WHERE student_id=?')->execute([$alpha]);
    $emails->send($sessions['alpha'],['new_email'=>'expired@example.invalid','email_confirmation'=>'expired@example.invalid']);
    $mail=$delivered[array_key_last($delivered)]; preg_match('/code is ([0-9]{6})/',$mail[2],$match);
    $pdo->prepare('UPDATE student_email_changes SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE student_id=?')->execute([$alpha]);
    $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$match[1]]),410,'Expired email code');
    $pdo->prepare('DELETE FROM student_email_changes WHERE student_id=?')->execute([$alpha]);
    $emails->send($sessions['alpha'],['new_email'=>'locked@example.invalid','email_confirmation'=>'locked@example.invalid']);
    $mail=$delivered[array_key_last($delivered)]; preg_match('/code is ([0-9]{6})/',$mail[2],$match);
    $wrong=$match[1]==='111111'?'222222':'111111';
    for($attempt=1;$attempt<=5;$attempt++) $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$wrong]),$attempt===5?429:422,'Email verification attempts');
    $reject(fn()=>$emails->verify($sessions['alpha'],['verification_code'=>$match[1]]),410,'Locked email code');
    $pdo->prepare('DELETE FROM student_email_changes WHERE student_id=?')->execute([$alpha]);
    $failed=new KnsStudentEmailChange($pdo,static function(){throw new RuntimeException('Fixture delivery unavailable');});
    $reject(fn()=>$failed->send($sessions['alpha'],['new_email'=>'unavailable@example.invalid','email_confirmation'=>'unavailable@example.invalid']),503,'Failed delivery');
    httpCheck($settings->state($sessions['alpha'])['profile']['email']===$send['new_email']&&!$settings->state($sessions['alpha'])['pending_email'],'Failed send changed email or left challenge');
    $pdo->prepare('UPDATE students SET requires_password_change=1 WHERE id=?')->execute([$beta]);
    $reject(fn()=>$settings->state($sessions['beta']),403,'Pending setup student access');
    $pdo->prepare('UPDATE students SET requires_password_change=0 WHERE id=?')->execute([$beta]);

    foreach(['php','admin','dashboard','module','request','config','js','css'] as $dir) copySetupTree($root.'/'.$dir,$app.'/'.$dir);
    foreach(['login.php','change-password.php'] as $file) copy($root.'/'.$file,$app.'/'.$file);
    mkdir($app.'/vendor');
    file_put_contents($app.'/vendor/autoload.php','<?php return require '.var_export($root.'/vendor/autoload.php',true).';');
    file_put_contents($app.'/db.php','<?php $pdo=new PDO('.var_export($dsn,true).','.var_export($username,true).','.var_export($password,true).',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);');
    file_put_contents($temporary.'/provider.php','<?php return [];');
    $router= <<<'ROUTER'
<?php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(is_file($_SERVER['DOCUMENT_ROOT'].$path))return false;
$root=ROOT_LITERAL;
$file=realpath($root.'/'.preg_replace('~^/kns_portal/~','',$path));
$types=['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','woff2'=>'font/woff2'];
$ext=strtolower(pathinfo($file?:'',PATHINFO_EXTENSION));
if(!$file||!str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$root).'/')||!isset($types[$ext])){http_response_code(404);exit;}
header('Content-Type: '.$types[$ext]);readfile($file);
ROUTER;
    file_put_contents($temporary.'/router.php',str_replace('ROOT_LITERAL',var_export($root,true),$router));
    $environment=getenv();
    foreach(['KNS_SMTP_USERNAME','KNS_SMTP_PASSWORD','KNS_SMTP_FROM_EMAIL','KNS_TWILIO_ACCOUNT_SID','KNS_TWILIO_AUTH_TOKEN','KNS_TWILIO_FROM_NUMBER'] as $name) $environment[$name]='';
    $environment['KNS_PASSWORD_RESET_CONFIG']=$temporary.'/provider.php';
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$temporary.'/sessions','-S',$address,'-t',$web,$temporary.'/router.php'],[0=>['pipe','r'],1=>['file',$temporary.'/server.log','a'],2=>['file',$temporary.'/server.log','a']],$pipes,$root,$environment,['bypass_shell'=>true]);
    if(!is_resource($server)) throw new RuntimeException('Could not start isolated HTTP server'); fclose($pipes[0]);
    for($attempt=0;$attempt<60;$attempt++){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(100000);}
    $base='http://'.$address.'/kns_portal'; $endpoint='/php/student/settings.php';
    $anonymous=new SetupBrowser($base);
    httpCheck($anonymous->request($endpoint,null,'application/json')['status']===401,'Anonymous settings access');
    $client=new SetupBrowser($base); $client->login('SETTINGS-ALPHA',$changedPassword); $client->token('/module/studentInformation.php');
    $other=new SetupBrowser($base); $other->login('SETTINGS-BETA',$initialPassword); $other->token('/module/studentInformation.php');
    httpCheck($client->request($endpoint,null,'application/json')['status']===200,'Student settings GET failed');
    httpCheck($client->request($endpoint,['action'=>'contacts','csrf'=>'wrong']+$contacts,'application/json')['status']===403,'Invalid CSRF accepted');
    foreach(['student_id'=>$beta,'student_number'=>'SETTINGS-BETA','role'=>'Administrator','course'=>'BSIT','email'=>'bypass@example.invalid','full_name'=>'Different name','password_hash'=>'bypass'] as $field=>$value){
        $result=$client->request($endpoint,['action'=>'contacts','csrf'=>$client->csrf,$field=>$value]+$contacts,'application/json');
        httpCheck($result['status']===403,'Protected field accepted: '.$field);
    }
    $saved=$client->request($endpoint,['action'=>'contacts','csrf'=>$client->csrf]+$contacts,'application/json');
    httpCheck($saved['status']===200,'Contact HTTP update failed: '.$saved['body']);
    httpCheck(str_contains($client->request('/module/studentInformation.php')['body'],'Fixture address'),'Profile did not reflect saved contacts');
    $securityHttp=['current_password'=>$changedPassword,'new_password'=>'Student-http-password-789','password_confirmation'=>'Student-http-password-789'];
    $response=$client->request($endpoint,['action'=>'security','csrf'=>$client->csrf]+$securityHttp,'application/json');
    httpCheck($response['status']===200,'Password HTTP update failed');
    httpCheck($client->request($endpoint,null,'application/json')['status']===200,'Password update destroyed session');
    httpCheck($other->request($endpoint,null,'application/json')['status']===200,'Password update destroyed another session');
    $old=new SetupBrowser($base);
    httpCheck(str_ends_with($old->login('SETTINGS-ALPHA',$changedPassword)['location'],'/login.php'),'Old password still authenticates');
    httpCheck(str_ends_with($old->login('SETTINGS-ALPHA',$securityHttp['new_password'])['location'],'/dashboard/dashboard.php'),'New password does not authenticate');
    $pdo->prepare("INSERT INTO admin_account(username,password_hash,admin_name,role,school_name) VALUES('settings.staff',?,'Fixture Admin','Administrator','Fixture school')")->execute([password_hash($initialPassword,PASSWORD_DEFAULT)]);
    $staff=new SetupBrowser($base); $staff->token();
    $staff->request('/php/auth/admin_login.php',['csrf'=>$staff->csrf,'student_number'=>'settings.staff','password'=>$initialPassword]);
    httpCheck($staff->request($endpoint,null,'application/json')['status']===401,'Staff accessed student settings');
    $response=$client->request($endpoint,['action'=>'send_email','csrf'=>$client->csrf,'new_email'=>'no-provider@example.invalid','email_confirmation'=>'no-provider@example.invalid'],'application/json');
    httpCheck($response['status']===503,'Unavailable mail must fail without changing email');
    httpCheck($settings->state($sessions['alpha'])['profile']['email']==='verified@example.invalid','Unavailable mail changed saved email');

    $pdo->exec("INSERT INTO announcements(announcement_type,title,description) VALUES('General','Fixture notice','Authored announcement content')");
    $announcementId=(int)$pdo->lastInsertId();
    $feed='/php/student/notifications.php';
    foreach([['0','0','0'],['1','0','0'],['0','0','1']] as [$announcements,$requests,$activity]){
        $flags=['notify_announcements'=>$announcements,'notify_requests'=>$requests,'notify_activity'=>$activity,'notify_email'=>'0'];
        httpCheck($client->request($endpoint,['action'=>'notifications','csrf'=>$client->csrf]+$flags,'application/json')['status']===200,'Notification preferences failed');
        $response=$client->request($feed,null,'application/json'); $data=json_decode($response['body'],true);
        httpCheck($response['status']===200,'Notification feed failed: '.$response['body']);
        if($announcements==='1'){
            httpCheck($data['total']===1&&$data['notifications'][0]['type']==='announcement','Announcement preference ignored');
            httpCheck($client->request($feed,['action'=>'read','type'=>'announcement','source_id'=>$announcementId,'csrf'=>$client->csrf],'application/json')['status']===200,'Announcement receipt failed');
        } elseif($activity==='1'){
            httpCheck($data['total']>0&&count(array_filter($data['notifications'],fn($row)=>$row['type']!=='activity'))===0,'Activity preference ignored');
            $activityId=$data['notifications'][0]['source_id'];
            httpCheck($client->request($feed,['action'=>'read','type'=>'activity','source_id'=>$activityId,'csrf'=>$client->csrf],'application/json')['status']===200,'Activity receipt failed');
            httpCheck($other->request($feed,['action'=>'read','type'=>'activity','source_id'=>$activityId,'csrf'=>$other->csrf],'application/json')['status']===404,'Another student read activity');
        } else httpCheck($data['total']===0&&$data['unread_count']===0,'Disabled notifications still visible');
    }
    httpCheck($client->request($feed,['action'=>'read_all','csrf'=>$client->csrf],'application/json')['status']===200,'Mark all read failed');
    $snapshot=$pdo->query('SELECT student_number,first_name,last_name,course,year_level,section FROM students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    httpCheck($snapshot[0]['student_number']==='SETTINGS-ALPHA'&&$snapshot[0]['first_name']==='Fixture'&&$snapshot[0]['course']==='BSCS','Student identity changed');
    $pdo->exec("UPDATE student_account_settings SET theme='light',language='en',notify_announcements=1,notify_requests=1,notify_activity=1,notify_email=0");
    $pdo->prepare('INSERT INTO student_email_changes(student_id,new_email,previous_email,code_hash,expires_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE))')
        ->execute([$alpha,'browser-verified@example.invalid','verified@example.invalid',password_hash('987654',PASSWORD_DEFAULT)]);
    if(in_array('--browser',$argv,true)){
        $artifacts=getenv('KNS_TEST_ARTIFACTS')?:sys_get_temp_dir().'/kns-student-settings-review';
        if(!is_dir($artifacts)) mkdir($artifacts,0700,true);
        $config=$temporary.'/browser.json';
        file_put_contents($config,json_encode(['base'=>$base,'temporary'=>$temporary,'artifacts'=>$artifacts,'password'=>$securityHttp['new_password'],'other_password'=>$initialPassword],JSON_THROW_ON_ERROR));
        $browser=proc_open(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',$root.'/tests/student_settings_browser.ps1','-ConfigPath',$config],[0=>['pipe','r'],1=>['file',$temporary.'/browser.log','a'],2=>['file',$temporary.'/browser.log','a']],$browserPipes,$root,null,['bypass_shell'=>true]);
        if(!is_resource($browser))throw new RuntimeException('Browser did not start'); fclose($browserPipes[0]);
        $status=proc_close($browser); echo file_get_contents($temporary.'/browser.log');
        httpCheck($status===0,'Student settings browser checks failed');
    }
    echo "PASS: $checks student settings/migration/security/email/HTTP checks; disposable fixtures only, no real messages.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $pdo=null; if($created)$source->exec('DROP DATABASE '.$database);
    if(is_dir($temporary)) removeSetupTree($temporary,$temporary);
}
