'use strict';
// Uses existing account/student records through read-only views. No account is created/copied/updated.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net');
const {spawn,execFileSync}=require('node:child_process');
const {randomBytes,randomUUID}=require('node:crypto');
const root=path.resolve(__dirname,'..'),php=process.env.KNS_TEST_PHP||'C:\\xampp\\php\\php.exe';
const database='kns_request_routing_test_'+randomBytes(6).toString('hex');
const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'kns-request-routing-'));
const webroot=path.join(temporary,'www'),app=path.join(webroot,'kns_portal');
const secret=randomBytes(24).toString('hex');
const connect="new PDO('mysql:host=localhost;dbname="+database+";charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])";
let checks=0,server,fixtures;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function check(condition,label){if(!condition)throw Error(label);checks++;}
function runPhp(code){return execFileSync(php,[],{input:'<?php\n'+code,cwd:root,encoding:'utf8',windowsHide:true});}
function setup(){
    fixtures=JSON.parse(runPhp(`
require 'db.php';
$source=$pdo;
$sourceName=$source->query('SELECT DATABASE()')->fetchColumn();
$quote=static fn($value)=>chr(96).str_replace(chr(96),chr(96).chr(96),$value).chr(96);
$sourcePrefix=$quote($sourceName).'.';
$accounts=$source->query("SELECT id,role FROM admin_account WHERE is_active=1 AND role IN ('Administrator','Registrar','OSA') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach(['Administrator','Registrar','OSA'] as $role)if(!array_filter($accounts,fn($row)=>$row['role']===$role))throw new RuntimeException('Existing active '.$role.' account required; no test account will be created.');
$students=$source->query("SELECT id FROM students WHERE status='Active' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if(count($students)<2)throw new RuntimeException('Two existing active students are required for ownership tests.');
$source->exec('CREATE DATABASE @DB@ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$target=@CONNECT@;
$target->exec('CREATE TABLE test_unavailable_offices (role VARCHAR(50) PRIMARY KEY)');
foreach(['admin_account','students'] as $table){
    $columns=$source->query('SHOW COLUMNS FROM '.$quote($table))->fetchAll(PDO::FETCH_ASSOC);
    $names=array_map(fn($column)=>'a.'.$quote($column['Field']),array_filter($columns,fn($column)=>$column['Field']!=='password_hash'));
    $filter=$table==='admin_account'?' WHERE NOT EXISTS(SELECT 1 FROM test_unavailable_offices u WHERE u.role=a.role)':'';
    $target->exec('CREATE ALGORITHM=TEMPTABLE VIEW '.$quote($table).' AS SELECT '.implode(',',$names).' FROM '.$sourcePrefix.$quote($table).' a'.$filter);
}
$target->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['requests','request_messages','request_attachments','admin_notification_reads','student_notification_reads','announcements'] as $table){
    $definition=$source->query('SHOW CREATE TABLE '.$quote($table))->fetch(PDO::FETCH_NUM)[1];
    foreach(['admin_account','students'] as $parent)$definition=str_replace('REFERENCES '.$quote($parent),'REFERENCES '.$sourcePrefix.$quote($parent),$definition);
    $target->exec($definition);
}
$target->exec('SET FOREIGN_KEY_CHECKS=1');
$target->exec('ALTER TABLE requests DROP INDEX IF EXISTS ix_request_office_status');
$target->exec('ALTER TABLE requests DROP COLUMN IF EXISTS assigned_office, DROP COLUMN IF EXISTS assignment_revision');
$types=['Module Request','Grade Record Request','Certificate of Enrollment','Good Moral Certificate','Certificate of Candidacy','Honorable Dismissal','Student ID Replacement','Other Request'];
$insert=$target->prepare('INSERT INTO requests(reference_no,student_id,request_type,academic_year,semester,purpose,submission_token,status) VALUES(?,?,?,?,?,?,?,?)');
foreach($types as $i=>$type)$insert->execute(['MIGRATION-'.$i,$students[0],$type,'2026 - 2027','1st Semester','Migration preservation test',sprintf('00000000-0000-4000-8000-%012d',$i),'Approved']);
$before=$target->query('SELECT id,reference_no,student_id,request_type,status,created_at FROM requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$sql=file_get_contents('database/migrations/20261105_request_routing.sql');
$sql=str_replace('REFERENCES admin_account','REFERENCES '.$sourcePrefix.$quote('admin_account'),$sql);
$target->exec($sql);
$after=$target->query('SELECT id,reference_no,student_id,request_type,status,created_at FROM requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if($before!==$after)throw new RuntimeException('Migration changed existing request data.');
$expected=['Admin','Registrar','Registrar','OSA','Registrar','Registrar','OSA','Admin'];
if($target->query('SELECT assigned_office FROM requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)!==$expected)throw new RuntimeException('Migration backfill mismatch.');
$target->exec("UPDATE requests SET assigned_office='OSA',assignment_revision=1 WHERE reference_no='MIGRATION-0'");
$target->exec($sql);
if($target->query("SELECT assigned_office FROM requests WHERE reference_no='MIGRATION-0'")->fetchColumn()!=='OSA')throw new RuntimeException('Migration overwrote an assignment on rerun.');
$target->exec('DELETE FROM requests');
echo json_encode(['accounts'=>$accounts,'students'=>array_map('intval',$students)]);
`.replaceAll('@DB@',database).replaceAll('@CONNECT@',connect)));
    checks+=3;
    fs.mkdirSync(app,{recursive:true});fs.mkdirSync(path.join(temporary,'sessions'));
    for(const dir of ['admin','php/auth','php/admin','php/request','php/student','dashboard','request','module']){
        fs.mkdirSync(path.dirname(path.join(app,dir)),{recursive:true});
        fs.cpSync(path.join(root,dir),path.join(app,dir),{recursive:true});
    }
    fs.copyFileSync(path.join(root,'php/announcement-common.php'),path.join(app,'php/announcement-common.php'));
    fs.copyFileSync(path.join(root,'login.php'),path.join(app,'login.php'));
    fs.writeFileSync(path.join(app,'db.php'),'<?php $pdo='+connect+';');
    const session=`<?php
require __DIR__.'/php/auth/session.php';require __DIR__.'/db.php';
if(!hash_equals('@SECRET@',$_GET['key']??'')){http_response_code(404);exit;}
$role=$_GET['role']??'';
if($role==='student'){
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
    $s=$pdo->prepare("SELECT id FROM students WHERE id=? AND status='Active'");$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
}else{
    $s=$pdo->prepare('SELECT id,role,admin_name FROM admin_account WHERE role=? AND is_active=1 ORDER BY id LIMIT 1');$s->execute([$role]);$row=$s->fetch(PDO::FETCH_ASSOC);
}
if(!$row){http_response_code(404);exit;}
$_SESSION=[];session_regenerate_id(true);
if($role==='student')$_SESSION['student_id']=(int)$row['id'];
elseif($role==='Administrator')$_SESSION['admin_id']=(int)$row['id'];
else{$_SESSION['office_id']=(int)$row['id'];$_SESSION['office_role']=$row['role'];$_SESSION['office_name']=$row['admin_name'];}
$_SESSION['csrf']=bin2hex(random_bytes(32));
header('Content-Type: application/json');echo json_encode(['id'=>(int)$row['id'],'csrf'=>$_SESSION['csrf']]);
`.replaceAll('@SECRET@',secret);
    fs.writeFileSync(path.join(app,'__test_session.php'),session);
    const router='<?php $url=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);$local=realpath($_SERVER["DOCUMENT_ROOT"].rawurldecode($url));if($local&&is_file($local))return false;'
        +'$prefix="/kns_portal/";if(!str_starts_with($url,$prefix)){http_response_code(404);exit;}'
        +'$file=realpath('+JSON.stringify(root.replace(/\\/g,'/'))+'."/".rawurldecode(substr($url,strlen($prefix))));'
        +'$root='+JSON.stringify(root.replace(/\\/g,'/')+'/')+';'
        +'$types=["css"=>"text/css","js"=>"application/javascript","png"=>"image/png","jpg"=>"image/jpeg","svg"=>"image/svg+xml","webp"=>"image/webp"];'
        +'if(!$file||!str_starts_with(str_replace(chr(92),"/",$file),$root)||!isset($types[pathinfo($file,PATHINFO_EXTENSION)])){http_response_code(404);exit;}'
        +'header("Content-Type: ".$types[pathinfo($file,PATHINFO_EXTENSION)]);readfile($file);';
    fs.writeFileSync(path.join(temporary,'router.php'),router);
}
class Session{
    constructor(base){this.base=base;this.cookies=new Map();this.csrf='';}
    async request(route,fields,method=fields?'POST':'GET'){
        const response=await fetch(this.base+route,{method,redirect:'manual',headers:{
            Cookie:[...this.cookies].map(([k,v])=>k+'='+v).join('; '),Accept:'application/json',
            ...(fields&&!(fields instanceof FormData)?{'Content-Type':'application/x-www-form-urlencoded'}:{})},
            body:fields instanceof FormData?fields:fields?new URLSearchParams(fields):undefined});
        for(const cookie of response.headers.getSetCookie()){const first=cookie.split(';')[0],i=first.indexOf('=');this.cookies.set(first.slice(0,i),first.slice(i+1));}
        const text=await response.text();let data;try{data=JSON.parse(text);}catch{}
        return{status:response.status,data,text};
    }
    async identity(role,id){
        const result=await this.request('/__test_session.php?key='+secret+'&role='+encodeURIComponent(role)+(id?'&id='+id:''));
        check(result.status===200,'Existing '+role+' identity available');
        this.csrf=result.data.csrf;this.id=result.data.id;return this;
    }
}
async function freePort(){const server=net.createServer();await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const n=server.address().port;await new Promise(resolve=>server.close(resolve));return n;}
function cleanup(){
    if(!/^kns_request_routing_test_[a-f0-9]{12}$/.test(database))throw Error('Unexpected test database.');
    runPhp("require 'db.php';$pdo->exec('DROP DATABASE IF EXISTS "+database+"');");
    const resolved=path.resolve(temporary);
    if(path.dirname(resolved)!==path.resolve(os.tmpdir())||!/^kns-request-routing-[A-Za-z0-9]{6}$/.test(path.basename(resolved)))throw Error('Unexpected test directory.');
    if(process.platform==='win32'){
        const script="$ErrorActionPreference='Stop'\n$target=[IO.Path]::GetFullPath($env:KNS_ROUTING_TEST_TEMP)\n"
            +"$base=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\\')\n"
            +"if([IO.Directory]::GetParent($target).FullName -ne $base -or [IO.Path]::GetFileName($target) -notmatch '^kns-request-routing-[A-Za-z0-9]{6}$'){throw 'Unexpected path'}\n"
            +"for($attempt=0;$attempt -lt 10;$attempt++){try{if(Test-Path -LiteralPath $target){Remove-Item -LiteralPath $target -Recurse -Force};break}catch{if($attempt -eq 9){throw};Start-Sleep -Milliseconds 300}}\n";
        execFileSync('powershell.exe',['-NoProfile','-NonInteractive','-Command','-'],{input:script,env:{...process.env,KNS_ROUTING_TEST_TEMP:resolved},windowsHide:true,stdio:['pipe','pipe','pipe']});
    }else fs.rmSync(resolved,{recursive:true,force:true});
}

const types={
    'Module Request':'Admin','Grade Record Request':'Registrar','Certificate of Enrollment':'Registrar',
    'Certificate of Candidacy':'Registrar','Honorable Dismissal':'Registrar',
    'Good Moral Certificate':'OSA','Student ID Replacement':'OSA','Other Request':'Admin'
};
const records='/php/request/records.php',messages='/php/request/messages.php',statusRoute='/php/admin/requests.php',forwardRoute='/php/admin/forward-request.php';
function requestFields(student,type){return{csrf:student.csrf,request_type:type,academic_year:'2026 - 2027',semester:'1st Semester',purpose:'Isolated routing workflow check',submission_token:randomUUID(),assigned_office:'Admin'};}
async function submit(student,type,attachment=false){
    const fields=requestFields(student,type);let body=fields;
    if(attachment){body=new FormData();for(const[k,v]of Object.entries(fields))body.set(k,v);body.append('attachments[]',new Blob(['Routing attachment'],{type:'text/plain'}),'routing.txt');}
    const response=await student.request('/php/request/create-request.php',body);
    check(response.status===201&&response.data?.ok,'Student submits '+type);
    check(response.data.request.assigned_office===types[type],'Automatic assignment: '+type);
    return{record:response.data.request,fields};
}
async function changeStatus(actor,record,action,expected=record.status){
    return actor.request(statusRoute,{csrf:actor.csrf,request_id:record.id,action,expected_status:expected,
        assignment_revision:record.assignment_revision,remarks:'Existing status workflow',client_token:randomUUID()});
}
function forwarding(admin,record,target){return{csrf:admin.csrf,request_id:record.id,assigned_office:target,expected_office:record.assigned_office,
    assignment_revision:record.assignment_revision,client_token:randomUUID()};}
async function httpTests(base){
    const anonymous=new Session(base);
    check((await anonymous.request(records)).status===401,'Anonymous request list denied');
    const admin=await new Session(base).identity('Administrator');
    const registrar=await new Session(base).identity('Registrar');
    const osa=await new Session(base).identity('OSA');
    const student=await new Session(base).identity('student',fixtures.students[0]);
    const second=await new Session(base).identity('student',fixtures.students[1]);
    const beforeAccounts=runPhp("require 'db.php';echo hash('sha256',serialize($pdo->query('SELECT * FROM admin_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));");
    const submitted={};
    for(const type of Object.keys(types))submitted[type]=await submit(student,type,type==='Grade Record Request');
    check((await student.request('/php/request/create-request.php',{...requestFields(student,'Other Request'),csrf:'invalid'})).status===403,'Submission requires CSRF');
    const first=submitted['Grade Record Request'];
    const repeated=await student.request('/php/request/create-request.php',first.fields);
    check(repeated.data.request.id===first.record.id,'Repeated submission does not duplicate request');
    for(const[session,office,amount]of[[admin,'Admin',8],[registrar,'Registrar',4],[osa,'OSA',2]]){
        const list=await session.request(records+'?per_page=50');
        check(list.status===200&&list.data.total===amount,office+' initial queue count');
        check(office==='Admin'||list.data.requests.every(row=>row.assigned_office===office),office+' scoped queue');
        check(list.data.summary.Total===amount,office+' scoped summary');
        check(list.data.can_forward===(office==='Admin'),office+' forwarding permission');
        const forced=await session.request(records+'?assigned_office=Admin&per_page=50');
        check(office==='Admin'||forced.data.requests.every(row=>row.assigned_office===office),office+' cannot override scope with query parameter');
        const feed=await session.request('/php/admin/notifications.php');
        check(feed.status===200&&feed.data.notifications.every(event=>list.data.requests.some(row=>row.id===event.request_id)),office+' scoped notifications');
    }
    const initial=await registrar.request(records+'?id='+first.record.id);
    check(initial.status===200&&initial.data.request.attachments.length===1,'Assigned office opens request details and attachment');
    const attachment=initial.data.request.attachments[0];
    check((await registrar.request('/php/request/attachment.php?id='+attachment.id)).text==='Routing attachment','Assigned office downloads original attachment');
    for(const session of[osa,second]){
        check((await session.request(records+'?id='+first.record.id)).status===404,'Unassigned office/other student cannot view detail');
        check((await session.request(messages+'?request_id='+first.record.id)).status===404,'Unassigned office/other student cannot view conversation');
        check((await session.request('/php/request/attachment.php?id='+attachment.id)).status===404,'Unassigned office/other student cannot download attachment');
        check((await session.request('/php/request/attachment.php?id='+attachment.id+'&preview=1')).status===404,'Unassigned office/other student cannot preview attachment');
    }
    check((await osa.request(messages,{csrf:osa.csrf,request_id:first.record.id,body:'Unassigned message',client_token:randomUUID()})).status===404,'Unassigned office cannot send messages');
    check((await changeStatus(osa,first.record,'approve')).status===404,'Unassigned office cannot update status');
    check((await registrar.request('/php/admin/notifications.php',{csrf:registrar.csrf,action:'read',type:'request',source_id:submitted['Good Moral Certificate'].record.id})).status===404,'Office cannot read another office notification');
    check((await registrar.request('/php/admin/notifications.php',{csrf:registrar.csrf,action:'read_all'})).status===200,'Scoped mark all notifications read');
    check((await student.request(messages,{csrf:student.csrf,request_id:first.record.id,body:'Student routing message',client_token:randomUUID()})).status===201,'Student conversation retained');
    check((await registrar.request(messages,{csrf:registrar.csrf,request_id:first.record.id,body:'Registrar routing reply',client_token:randomUUID()})).status===201,'Assigned office conversation reply');
    const process=await changeStatus(registrar,first.record,'processing');
    check(process.status===200&&process.data.request.status==='Processing','Registrar uses existing Processing status');
    let current=process.data.request;
    check((await student.request(records+'?id='+current.id)).data.request.status==='Processing','Student sees current Processing status');
    check((await admin.request(forwardRoute,{...forwarding(admin,current,'OSA'),csrf:'invalid'})).status===403,'Forwarding requires CSRF');
    for(const actor of[registrar,osa,student]){
        check((await actor.request(forwardRoute,{...forwarding(actor,current,'OSA'),admin_id:admin.id,role:'Administrator'})).status===403,'Only actual Admin may forward');
    }
    check((await admin.request(forwardRoute,{...forwarding(admin,current,'OSA'),assigned_office:'Teacher'})).status===422,'Unknown office rejected');
    const original={student_id:current.student_id,reference_no:current.reference_no,request_type:current.request_type,created_at:current.created_at};
    const forwardFields=forwarding(admin,current,'OSA');
    const forwarded=await admin.request(forwardRoute,forwardFields);
    check(forwarded.status===200&&forwarded.data.request.assigned_office==='OSA'&&forwarded.data.request.status==='Processing','Admin forwards without resetting status');
    current=forwarded.data.request;
    check(Object.entries(original).every(([key,value])=>current[key]===value),'Forwarding preserves request details');
    check(current.assignment_history.length===1&&current.assignment_history[0].previous_office==='Registrar'
        &&current.assignment_history[0].new_office==='OSA'&&current.assignment_history[0].reassigned_by_admin_id===admin.id
        &&!!current.assignment_history[0].created_at,'Previous/new office, Admin and time recorded');
    check((await admin.request(forwardRoute,forwardFields)).data.duplicate,'Forwarding retry is idempotent');
    check((await admin.request(forwardRoute,{...forwardFields,assigned_office:'Admin'})).status===409,'Token cannot be reused for another target');
    check((await admin.request(forwardRoute,{...forwardFields,client_token:randomUUID(),assigned_office:'Admin'})).status===409,'Stale forwarding revision rejected');
    for(const route of[records+'?id='+current.id,messages+'?request_id='+current.id,'/php/request/attachment.php?id='+attachment.id]){
        check((await registrar.request(route)).status===404,'Previous office loses all request access');
    }
    check((await changeStatus(registrar,current,'approve')).status===404,'Previous office cannot update forwarded request');
    check((await registrar.request(messages,{csrf:registrar.csrf,request_id:current.id,body:'Old office',client_token:randomUUID()})).status===404,'Previous office cannot send after forwarding');
    const newDetail=await osa.request(records+'?id='+current.id);
    check(newDetail.status===200&&newDetail.data.request.assignment_history.length===1,'New office receives request/history');
    const conversation=await osa.request(messages+'?request_id='+current.id);
    check(conversation.status===200&&conversation.data.messages.length===3,'Conversation and status history preserved across offices');
    check((await osa.request('/php/request/attachment.php?id='+attachment.id)).text==='Routing attachment','New office receives original attachment');
    const approved=await changeStatus(osa,current,'approve');
    check(approved.status===200&&approved.data.request.status==='Approved','OSA processes forwarded request using existing Approve');
    current=approved.data.request;
    check((await student.request(records+'?id='+current.id)).data.request.status==='Approved','Student sees final Approved status');
    const toAdmin=await admin.request(forwardRoute,forwarding(admin,current,'Admin'));
    check(toAdmin.status===200&&toAdmin.data.request.status==='Approved'&&toAdmin.data.request.assignment_history.length===2,'Admin can reassign an approved request with history intact');
    check((await osa.request(records+'?id='+current.id)).status===404,'Previous OSA loses access after forwarding to Admin');
    const retrySubmission=await student.request('/php/request/create-request.php',first.fields);
    check(retrySubmission.data.request.assigned_office==='Admin','Submission retry never overwrites a later assignment');
    const moral=submitted['Good Moral Certificate'].record;
    const rejected=await changeStatus(osa,moral,'reject');
    check(rejected.status===200&&rejected.data.request.status==='Rejected','Existing Rejected status supported');
    const rejectedForward=await admin.request(forwardRoute,forwarding(admin,rejected.data.request,'Registrar'));
    check(rejectedForward.status===200&&rejectedForward.data.request.status==='Rejected','Rejected requests can be forwarded without resetting status');
    const other=submitted['Other Request'].record;
    const otherForward=await admin.request(forwardRoute,forwarding(admin,other,'Registrar'));
    check(otherForward.status===200,'Admin forwards its own queue to Registrar');
    check((await registrar.request(records+'?per_page=50')).data.requests.some(row=>row.id===other.id),'Forwarded request appears in new office list');
    const secondRequest=await submit(second,'Module Request');
    check((await student.request(records+'?per_page=50')).data.requests.every(row=>row.id!==secondRequest.record.id),'Student cannot list another student requests');
    const unavailable=runPhp('$pdo='+connect+';$pdo->prepare("INSERT INTO test_unavailable_offices VALUES (?)")->execute(["Registrar"]);echo "ok";');
    check(unavailable.trim()==='ok','Simulate missing active office without changing any account');
    const missing=await submit(student,'Certificate of Enrollment');
    const warning=await admin.request(records);
    check(warning.data.routing_warnings.some(message=>message.includes('No active Registrar account')),'Admin sees clear missing-office warning');
    check(warning.data.office_availability.Registrar===0,'Availability comes from existing active account roles');
    const missingForward=await admin.request(forwardRoute,forwarding(admin,submitted['Module Request'].record,'Registrar'));
    check(missingForward.status===200&&missingForward.data.routing_warning.includes('No active Registrar'),'Unstaffed queue forwarding gives clear Admin warning');
    check((await registrar.request(records)).status===401,'Unavailable office identity is rejected');
    runPhp('$pdo='+connect+';$pdo->exec("DELETE FROM test_unavailable_offices");');
    check((await registrar.request(records+'?per_page=50')).data.requests.some(row=>row.id===missing.record.id),'Saved queue is available to existing active office again');
    check(beforeAccounts===runPhp("require 'db.php';echo hash('sha256',serialize($pdo->query('SELECT * FROM admin_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));"),'Every existing account record and password remains unchanged');
    return{admin,registrar,osa,student,submitted,forwardedId:other.id};
}

async function browserTests(base,context){
    const chrome=process.env.KNS_TEST_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const profile=path.join(temporary,'chrome');
    const browser=spawn(chrome,['--headless','--disable-gpu','--no-first-run','--no-default-browser-check',
        '--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
    let socket,next=0;const pending=new Map(),errors=[];
    async function until(action,label,timeout=22000){const end=Date.now()+timeout;while(Date.now()<end){if(await action())return;await delay(90);}throw Error('Timed out: '+label);}
    const send=(method,params={})=>new Promise((resolve,reject)=>{
        const id=++next,timeout=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout '+method));},20000);
        pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params}));
    });
    const evaluate=async(fn,...args)=>{
        const data=await send('Runtime.evaluate',{expression:'('+fn.toString()+')(...'+JSON.stringify(args)+')',awaitPromise:true,returnByValue:true});
        if(data.exceptionDetails)throw Error(data.exceptionDetails.exception?.description||data.exceptionDetails.text);
        return data.result.value;
    };
    const waitFor=(fn,label)=>until(()=>evaluate(fn),label);
    const click=selector=>evaluate(selector=>{const button=document.querySelector(selector);if(!button)throw Error('Missing '+selector);button.focus();button.click();},selector);
    const screenshot=async name=>{
        if(!process.env.KNS_TEST_ARTIFACTS)return;
        fs.mkdirSync(process.env.KNS_TEST_ARTIFACTS,{recursive:true});
        const result=await send('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync(path.join(process.env.KNS_TEST_ARTIFACTS,name+'.png'),Buffer.from(result.data,'base64'));
    };
    const layout=()=>['.header-main-contianer','.sidebar-contianer'].map(selector=>{const r=document.querySelector(selector).getBoundingClientRect();return[r.x,r.y,r.width,r.height];});
    async function identity(session,student=false){
        await send('Network.clearBrowserCookies');
        for(const[name,value]of session.cookies)await send('Network.setCookie',{name,value,url:base,path:'/'});
        await send('Page.navigate',{url:base+(student?'/dashboard/dashboard.php':'/admin/dashboard.php')});
        await waitFor(()=>typeof window.loadPage==='function'&&!!window.KNSRequests,'Dashboard');
        await evaluate(student=>window.loadPage(student?'../module/request.html':'requests.html'),student);
        await waitFor(()=>{const table=document.querySelector('.request-table,.my-requests-table');return table&&table.getAttribute('aria-busy')==='false';},'Request list');
    }
    async function detail(id){
        await evaluate(id=>window.loadPage('requests.html',{requestId:id}),id);
        await waitFor(()=>document.querySelector('#requestDetailsModal')?.classList.contains('is-open'),'Request Details');
    }
    try{
        let port;
        await until(()=>{try{port=+fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];return port;}catch(e){if(['ENOENT','EBUSY','EPERM'].includes(e.code))return false;throw e;}},'Chrome');
        const pages=await(await fetch('http://127.0.0.1:'+port+'/json/list')).json();
        socket=new WebSocket(pages.find(page=>page.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        socket.onmessage=event=>{
            const data=JSON.parse(event.data);
            if(data.id&&pending.has(data.id)){const p=pending.get(data.id);pending.delete(data.id);clearTimeout(p.timeout);data.error?p.reject(Error(data.error.message)):p.resolve(data.result);}
            else if(data.method==='Runtime.exceptionThrown')errors.push(data.params.exceptionDetails.exception?.description||data.params.exceptionDetails.text);
        };
        await send('Page.enable');await send('Network.enable');await send('Runtime.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1672,height:941,deviceScaleFactor:1,mobile:false});
        await identity(context.registrar);
        const before=await evaluate(layout);
        check(await evaluate(()=>[...document.querySelectorAll('.request-routing-office')].every(node=>node.textContent==='Registrar')),'Registrar browser queue is office-scoped');
        await detail(context.forwardedId);
        check(await evaluate(()=>document.querySelector('[data-request-field=assigned_office]').textContent==='Registrar'
            &&!document.querySelector('.request-routing-form')),'Office detail shows assignment without Admin forwarding controls');
        await screenshot('registrar-assigned-request');
        const current=(await context.admin.request(records+'?id='+context.forwardedId)).data.request;
        check((await context.admin.request(forwardRoute,forwarding(context.admin,current,'OSA'))).status===200,'Admin forwards while old office has details open');
        await click('.rd-processing');
        await waitFor(()=>!document.querySelector('#requestDetailsModal').classList.contains('is-open'),'Old office detail closes');
        await until(()=>evaluate(id=>!document.querySelector('tr[data-request-id="'+id+'"]'),context.forwardedId),'Old office row removed');
        check(true,'Old office cannot act on forwarded request and loses the stale UI');
        await identity(context.osa);
        check(await evaluate(id=>!!document.querySelector('tr[data-request-id="'+id+'"]'),context.forwardedId),'New office browser receives forwarded request');
        await detail(context.forwardedId);
        check(await evaluate(()=>document.querySelector('[data-request-field=assigned_office]').textContent==='OSA'
            &&!document.querySelector('.request-routing-form')),'New office uses existing details without forwarding privilege');
        await click('.rd-processing');
        await waitFor(()=>document.querySelector('.rd-status').textContent==='Processing','Office status update');
        check((await context.student.request(records+'?id='+context.forwardedId)).data.request.status==='Processing','Student sees browser office status update');
        await screenshot('osa-processing-request');
        check(JSON.stringify(before)===JSON.stringify(await evaluate(layout)),'Routing leaves sidebar/header geometry unchanged');
        await identity(context.admin);
        await detail(context.forwardedId);
        check(await evaluate(()=>!!document.querySelector('.request-routing-form')),'Admin forwarding controls appear');
        await evaluate(()=>{const select=document.querySelector('.request-routing-select');select.value='Admin';select.dispatchEvent(new Event('change',{bubbles:true}));});
        await click('.request-routing-forward');
        await waitFor(()=>document.querySelector('[data-request-field=assigned_office]').textContent==='Admin','Admin browser forwarding');
        check(await evaluate(()=>document.querySelector('.rd-status').textContent==='Processing'
            &&document.querySelectorAll('.request-routing-history li').length===3),'Browser forwarding preserves status and displays history');
        await waitFor(()=>document.querySelector('.request-routing-forward').textContent==='Forward Request','Forwarding finishes');
        check(await evaluate(()=>document.querySelector('.request-routing-history').textContent.includes('\u2192')),'Assignment history uses readable arrows');
        await screenshot('admin-forwarding-history');
        const approvedId=context.submitted['Grade Record Request'].record.id;
        await detail(approvedId);
        check(await evaluate(()=>document.querySelector('.rd-approve').disabled&&document.querySelector('.rd-status').textContent==='Approved'),'Approved request retains existing final-status controls');
        await evaluate(()=>{const select=document.querySelector('.request-routing-select');select.value='Registrar';select.dispatchEvent(new Event('change',{bubbles:true}));});
        check(await evaluate(()=>!document.querySelector('.request-routing-forward').disabled),'Admin can forward a final request');
        await click('.request-routing-forward');
        await waitFor(()=>document.querySelector('[data-request-field=assigned_office]').textContent==='Registrar','Forward approved request');
        check(await evaluate(()=>document.querySelector('.rd-status').textContent==='Approved'),'Final status preserved in browser');
        runPhp('$pdo='+connect+';$pdo->prepare("INSERT INTO test_unavailable_offices VALUES (?)")->execute(["OSA"]);');
        await evaluate(()=>window.loadPage('requests.html'));
        await waitFor(()=>{const p=document.querySelector('.request-routing-warning');return p&&!p.hidden;},'Missing office warning');
        check(await evaluate(()=>document.querySelector('.request-routing-warning').textContent.includes('No active OSA account')),'Admin browser missing-office warning');
        await screenshot('admin-missing-office-warning');
        runPhp('$pdo='+connect+';$pdo->exec("DELETE FROM test_unavailable_offices");');
        await identity(context.student,true);
        check(await evaluate(id=>document.querySelector('tr[data-request-id="'+id+'"] .request-status')?.textContent==='Processing',context.forwardedId),'Existing student page displays current request status');
        check(await evaluate(()=>!document.querySelector('.request-routing-form')),'Student cannot access forwarding controls');
        await screenshot('student-request-form');
        await evaluate(()=>document.querySelector('.my-requests-table').scrollIntoView({block:'center'}));
        await screenshot('student-current-status');
        check(errors.length===0,'No browser JavaScript exceptions: '+errors.join('; '));
        console.log('Browser routing, office processing, forwarding/history, warnings, and student status passed.');
    }catch(error){console.error(error.message);await screenshot('routing-test-failure');throw error;}
    finally{
        if(socket?.readyState===1)await send('Browser.close').catch(()=>{});
        socket?.close();
        if(browser.exitCode===null){const stopped=new Promise(resolve=>browser.once('exit',resolve));await Promise.race([stopped,delay(3000)]);}
        if(browser.exitCode===null)browser.kill();
    }
}

(async()=>{
    try{
        setup();
        if(process.argv.includes('--migration-only')){console.log('Migration preservation and safe rerun passed.');return;}
        const port=await freePort(),base='http://127.0.0.1:'+port+'/kns_portal';
        server=spawn(php,['-d','session.save_path='+path.join(temporary,'sessions'),'-S','127.0.0.1:'+port,'-t',webroot,path.join(temporary,'router.php')],
            {cwd:root,windowsHide:true,env:{...process.env,KNS_REQUEST_STORAGE:path.join(temporary,'private')},stdio:['ignore','ignore','pipe']});
        let log='';server.stderr.on('data',chunk=>{log+=chunk;});
        for(let i=0;i<60;i++){try{await fetch(base+'/__test_session.php');break;}catch{await delay(100);}}
        const context=await httpTests(base);
        if(process.argv.includes('--browser'))await browserTests(base,context);
        console.log('Passed '+checks+' routing checks. Existing account records and passwords were not modified.');
    }finally{
        if(server&&server.exitCode===null){const stopped=new Promise(resolve=>server.once('exit',resolve));server.kill();await Promise.race([stopped,delay(3000)]);}
        cleanup();
    }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
