'use strict';
// Run with Node 22+ from the portal root. All writes use a disposable database/web root.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {spawn, execFileSync} = require('node:child_process');
const {randomBytes, randomUUID} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const php = process.env.KNS_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const database = 'kns_office_accounts_test_' + randomBytes(6).toString('hex');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'kns-office-dynamic-'));
const app = path.join(temporary, 'kns_portal');
const adminPassword = 'Temporary-Admin-Password-Only';
const officePassword = 'Temporary-Office-Password-Only';
let server;
let checks = 0;
function check(value, label) {
    if (!value) throw new Error(label);
    checks++;
}
function runPhp(code) {
    return execFileSync(php, [], {input: '<?php\n' + code, cwd: root, encoding: 'utf8', windowsHide: true});
}
const connect = "new PDO('mysql:host=localhost;dbname=" + database + ";charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])";
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function port() {
    const socket = net.createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const value = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    return value;
}
function setup() {
    runPhp(`
require 'db.php';
$source = $pdo;
$source->exec('CREATE DATABASE @DB@ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$target = @CONNECT@;
$target->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($source->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $row) {
    $identifier = chr(96) . str_replace(chr(96), chr(96).chr(96), $row[0]) . chr(96);
    $definition = $source->query('SHOW CREATE TABLE '.$identifier)->fetch(PDO::FETCH_NUM)[1];
    $target->exec($definition);
}
$target->exec('SET FOREIGN_KEY_CHECKS = 1');
$target->exec(file_get_contents('database/migrations/20261105_office_accounts.sql'));
$target->exec(file_get_contents('database/migrations/20261105_office_accounts.sql'));
$insert = $target->prepare('INSERT INTO admin_account (id, username, password_hash, admin_name, role, school_name, school_email, school_contact) VALUES (1,?,?,?,?,?,?,?)');
$insert->execute(['test.admin',password_hash('@ADMINPW@',PASSWORD_DEFAULT),'Test Administrator','Administrator','Test School','admin@example.test','09123456789']);
$insert = $target->prepare('INSERT INTO students (id,student_number,password_hash,first_name,last_name,course,year_level,section,status,email) VALUES (1,?,?,?,?,?,1,?,?,?)');
$insert->execute(['TEST-STUDENT',password_hash('Temporary-Student-Password',PASSWORD_DEFAULT),'Sample','Student','BSIT','A','Active','student@example.test']);
$insert = $target->prepare('INSERT INTO requests (id,reference_no,student_id,request_type,academic_year,semester,purpose,submission_token,assigned_office) VALUES (1,?,1,?,?,?,?,?,?)');
$insert->execute(['TEST-REQUEST','Grade Record Request','2026-2027','First Semester','Test request','00000000-0000-4000-8000-000000000001','Registrar']);
echo 'Prepared isolated database.';
`.replaceAll('@DB@',database).replaceAll('@CONNECT@',connect).replaceAll('@ADMINPW@',adminPassword));
    fs.mkdirSync(app);
    for (const dir of ['admin', 'php/auth', 'php/admin', 'php/request', 'dashboard', 'request', 'css']) {
        fs.mkdirSync(path.dirname(path.join(app, dir)), {recursive: true});
        fs.cpSync(path.join(root, dir), path.join(app, dir), {recursive: true});
    }
    fs.copyFileSync(path.join(root, 'login.php'), path.join(app, 'login.php'));
    fs.copyFileSync(path.join(root, 'php/announcement-common.php'), path.join(app, 'php/announcement-common.php'));
    fs.mkdirSync(path.join(app,'vendor'));
    fs.writeFileSync(path.join(app,'vendor/autoload.php'),'<?php return require '+JSON.stringify(path.join(root,'vendor/autoload.php').replace(/\\/g,'/'))+';');
    fs.writeFileSync(path.join(app, 'db.php'), '<?php $pdo = ' + connect + ';');
    // Serve only non-executable static assets from the original root.
    const router = '<?php $url=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'
        + '$local=realpath($_SERVER["DOCUMENT_ROOT"].rawurldecode($url));if($local&&is_file($local))return false;'
        + '$prefix="/kns_portal/";if(!str_starts_with($url,$prefix)){http_response_code(404);exit;}'
        + '$file=realpath(' + JSON.stringify(root.replace(/\\/g, '/')) + '."/".rawurldecode(substr($url,strlen($prefix))));'
        + '$root=' + JSON.stringify(root.replace(/\\/g, '/') + '/') + ';'
        + '$types=["css"=>"text/css","js"=>"application/javascript","png"=>"image/png","jpg"=>"image/jpeg","jpeg"=>"image/jpeg","svg"=>"image/svg+xml","webp"=>"image/webp","woff2"=>"font/woff2"];'
        + '$ext=strtolower(pathinfo($file?: "",PATHINFO_EXTENSION));if(!$file||!str_starts_with(str_replace(chr(92),"/",$file),$root)||!isset($types[$ext])){http_response_code(404);exit;}'
        + 'header("Content-Type: ".$types[$ext]);readfile($file);';
    fs.writeFileSync(path.join(temporary, 'router.php'), router);
}
class BrowserSession {
    constructor(base) { this.base = base; this.cookies = new Map(); this.csrf = ''; }
    async request(route, fields, method = fields ? 'POST' : 'GET', accept = '') {
        const response = await fetch(this.base + route, {
            method, redirect: 'manual',
            headers: {Cookie: [...this.cookies].map(([key,value]) => key + '=' + value).join('; '),
                ...(accept ? {Accept: accept} : {}),
                ...(fields && !(fields instanceof FormData) ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})},
            body: fields instanceof FormData ? fields : fields ? new URLSearchParams(fields) : undefined
        });
        for (const cookie of response.headers.getSetCookie()) {
            const first = cookie.split(';')[0], split = first.indexOf('=');
            this.cookies.set(first.slice(0,split),first.slice(split+1));
        }
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch {}
        return {status: response.status, location: response.headers.get('location'), text, data};
    }
    async token() {
        const response = await this.request('/login.php');
        this.csrf = response.text.match(/name="csrf"\s+value="([a-f0-9]+)"/)?.[1];
        check(!!this.csrf, 'Login CSRF token available');
    }
    async login(username, password, student = false) {
        await this.token();
        const response = await this.request('/php/auth/' + (student ? 'login.php' : 'admin_login.php'),
            {csrf: this.csrf, student_number: username, password});
        await this.token();
        return response;
    }
}
const endpoint = '/php/admin/office-accounts.php';
async function httpTests(base) {
    const anonymous = new BrowserSession(base);
    check((await anonymous.request(endpoint)).status === 401, 'Anonymous list denied');
    check((await anonymous.request(endpoint, {action: 'create'})).status === 401, 'Anonymous creation denied');
    const admin = new BrowserSession(base);
    check((await admin.login('test.admin', adminPassword)).location.endsWith('/admin/dashboard.php'), 'Existing Admin login');
    const dashboard = await admin.request('/admin/dashboard.php');
    check(dashboard.status === 200 && dashboard.text.includes('Admin Profile') && dashboard.text.includes('Overview'), 'Admin shell remains intact');
    const initial = await admin.request(endpoint);
    check(initial.status === 200 && initial.data.accounts.length === 0, 'Real empty list');
    const valid = {action: 'create', csrf: admin.csrf, full_name: 'Registrar Staff', username: 'registrar.test',
        email: 'registrar@example.test', phone_number: '+63 912 345 6789', role: 'Registrar',
        password: officePassword, password_confirmation: officePassword};
    check((await admin.request(endpoint, {...valid, csrf: 'invalid'})).status === 403, 'Creation requires CSRF');
    check((await admin.request(endpoint, {...valid, role: 'Administrator'})).status === 422, 'Cannot create Admin');
    check((await admin.request(endpoint, {...valid, role: 'student'})).status === 422, 'Cannot create student');
    check((await admin.request(endpoint, {...valid, email: 'invalid'})).data.errors.email, 'Server email validation');
    check((await admin.request(endpoint, {...valid, password_confirmation: 'different'})).status === 422, 'Server password matching');
    check((await admin.request(endpoint, {...valid, phone_number: ''})).status === 422, 'Phone is required');
    const created = await admin.request(endpoint, valid);
    check(created.status === 201 && created.data.account.is_active, 'Registrar created');
    const registrarId = created.data.account.id;
    check(created.data.account.phone_number === valid.phone_number && created.data.account.username === valid.username, 'Details returned correctly');
    check(!created.text.includes('password') && !created.text.includes(officePassword), 'Creation has no password material');
    check((await admin.request(endpoint, {...valid, email: 'unique@example.test'})).status === 409, 'Duplicate username denied');
    check((await admin.request(endpoint, {...valid, username: 'unique.user'})).status === 409, 'Duplicate email denied');
    const osaCreated = await admin.request(endpoint, {...valid, full_name: 'OSA Staff', username: 'osa.test', email: 'osa@example.test', role: 'OSA'});
    check(osaCreated.status === 201 && osaCreated.data.account.role === 'OSA', 'OSA created');
    const list = await admin.request(endpoint);
    check(list.data.accounts.length === 2 && !list.text.includes('password'), 'Real role-filtered safe list');
    const registrar = new BrowserSession(base);
    check((await registrar.login('registrar.test', officePassword)).location.endsWith('/admin/dashboard.php'), 'Registrar signs in through existing login');
    const officeShell = await registrar.request('/admin/dashboard.php');
    check(officeShell.status === 200 && officeShell.text.includes('Registrar services')
        && officeShell.text.includes('<p>Overview</p>') && officeShell.text.includes('<p>Results</p>')
        && officeShell.text.includes('<p>My Profile</p>') && !officeShell.text.includes('Admin Profile'), 'Registrar navigation includes permitted pages');
    for (const fields of [null, {...valid, csrf: registrar.csrf}, {action: 'deactivate', csrf: registrar.csrf, account_id: registrarId, admin_password: adminPassword}]) {
        check((await registrar.request(endpoint, fields)).status === 403, 'Office cannot manage accounts');
    }
    check((await registrar.request('/admin/profile.php')).status === 200, 'Registrar can access own profile');
    const records = await registrar.request('/php/request/records.php');
    check(records.status === 200 && records.data.role === 'admin', 'Active office can access existing request workspace');
    const sent = await registrar.request('/php/request/messages.php', {csrf: registrar.csrf, request_id: 1, body: 'Historical office reply', client_token: randomUUID()});
    check(sent.status === 201 || sent.status === 200, 'Office reply uses existing message history and foreign key');
    const osa = new BrowserSession(base);
    check((await osa.login('osa.test', officePassword)).location.endsWith('/admin/dashboard.php'), 'OSA signs in');
    const osaShell = await osa.request('/admin/dashboard.php');
    check(osaShell.text.includes('OSA services') && osaShell.text.includes('<p>Overview</p>')
        && osaShell.text.includes('<p>My Profile</p>') && !osaShell.text.includes('<p>Results</p>'), 'OSA navigation excludes Results');
    await officeRoleTests(base,admin,registrar,osa);
    const student = new BrowserSession(base);
    check((await student.login('TEST-STUDENT', 'Temporary-Student-Password', true)).location.endsWith('/dashboard/dashboard.php'), 'Existing student login works');
    check((await student.request(endpoint)).status === 403, 'Student cannot manage accounts');
    check((await student.request(endpoint, {...valid, csrf: student.csrf})).status === 403, 'Student creation denied');
    const deactivate = {action: 'deactivate', csrf: admin.csrf, account_id: registrarId, admin_password: adminPassword};
    check((await admin.request(endpoint, {...deactivate, csrf: 'invalid'})).status === 403, 'Deactivation requires CSRF');
    check((await admin.request(endpoint, {...deactivate, admin_password: officePassword})).status === 422, 'Office password is not Admin password');
    check((await admin.request(endpoint, {...deactivate, admin_password: 'wrong'})).status === 422, 'Wrong Admin password denied');
    check((await admin.request(endpoint, {...deactivate, account_id: 1})).status === 404, 'Cannot deactivate Admin through office feature');
    const removed = await admin.request(endpoint, deactivate);
    check(removed.status === 200 && !removed.data.account.is_active, 'Current signed-in Admin password deactivates');
    check((await admin.request(endpoint)).data.accounts.some(account => account.id === registrarId && !account.is_active), 'Inactive account retained in list');
    check((await registrar.request('/php/request/records.php')).status === 401, 'Old deactivated office session is rejected');
    check((await registrar.request('/admin/dashboard.php')).status === 302, 'Deactivated dashboard session rejected');
    for(const route of ['/admin/overview.php','/admin/results.php','/admin/profile.php']) {
        check((await registrar.request(route,null,'GET','application/json')).status===401,'Deactivated office denied '+route);
    }
    const inactiveLogin = new BrowserSession(base);
    check((await inactiveLogin.login('registrar.test', officePassword)).location.endsWith('/login.php'), 'Deactivated account cannot sign in');
    const history = runPhp('$pdo='+connect+';echo $pdo->query("SELECT COUNT(*) FROM request_messages WHERE sender_admin_id='+registrarId+'")->fetchColumn();');
    check(history.trim() === '1', 'Account request history preserved after deactivation');
    check((await admin.request(endpoint, valid)).status === 409, 'Inactive username remains reserved');
    check((await admin.login('test.admin', adminPassword)).location.endsWith('/admin/dashboard.php'), 'Admin can still sign in after changes');
    check((await student.login('TEST-STUDENT', 'Temporary-Student-Password', true)).location.endsWith('/dashboard/dashboard.php'), 'Student can still sign in after changes');
    return {admin, osa, osaId: osaCreated.data.account.id};
}

async function officeRoleTests(base, admin, registrar, osa) {
    for (const [session, role] of [[registrar,'Registrar'],[osa,'OSA']]) {
        const overview=await session.request('/admin/overview.php');
        check(overview.status===200 && overview.text.includes('data-create-post') && overview.text.includes('data-post-form'),role+' full Overview');
        const nonce=overview.text.match(/name="submission_token" value="([a-f0-9]+)"/)?.[1];
        const published=await session.request('/admin/create_post.php',{csrf:session.csrf,submission_token:nonce,
            announcement_type:'Other',duration:'none',title:role+' announcement',highlights:'School update',description:'Office publishing test.'},'POST','application/json');
        check(published.status===201 || published.status===200,role+' can publish announcements');
        const announcementId=published.data?.announcement_id;
        check(Number.isInteger(announcementId),role+' receives saved post ID');
        const deleted=await session.request('/admin/delete_post.php',{csrf:session.csrf,announcement_id:announcementId,confirm_delete:'1'},'POST','application/json');
        check(deleted.status===200,role+' can delete announcements');
        const profile=await session.request('/admin/profile.php');
        check(profile.status===200 && profile.text.includes(role+' Profile') && profile.text.includes('Office Information'),role+' own profile');
        check(!profile.text.includes('data-open-office-registration') && !profile.text.includes('data-open-office-accounts')
            && !profile.text.includes('id="kns-office-registration"'),role+' profile excludes Admin account controls');
        check(!profile.text.includes('id="admin-profile-form"') && !profile.text.includes('class="admin-profile-actions"'),role+' information card is read-only');
        // Preference rows were created by the read-only profile GET. Suppress optional mail in these disposable accounts.
        runPhp('$pdo='+connect+';$pdo->exec("UPDATE staff_account_settings SET notify_email=0");');
        const settings='/php/admin/staff-settings.php';
        const update={csrf:session.csrf,action:'profile',full_name:role+' Updated Staff'};
        check((await session.request('/admin/profile.php',{},'POST','application/json')).status===405,role+' legacy profile endpoint is read-only');
        check((await session.request(settings,{...update,csrf:'wrong'},'POST','application/json')).status===403,role+' settings requires CSRF');
        check((await session.request(settings,{...update,role:'Administrator',id:1},'POST','application/json')).status===403,role+' settings rejects identity overrides');
        check((await session.request(settings,{...update,school_name:'Unauthorized School'},'POST','application/json')).status===403,role+' school name remains protected');
        const duplicate=await session.request(settings,{csrf:session.csrf,action:'send_email',new_email:'admin@example.test',email_confirmation:'admin@example.test'},'POST','application/json');
        check(duplicate.status===409,role+' cannot reuse an email');
        const saved=await session.request(settings,update,'POST','application/json');
        check(saved.status===200 && saved.data.state.profile.role===role,role+' settings saves own name');
        const contacts=await session.request(settings,{csrf:session.csrf,action:'contacts',phone_number:'09123456789',alternate_contact:'',address:'Test office address'},'POST','application/json');
        check(contacts.status===200 && contacts.data.state.profile.address==='Test office address',role+' settings saves contact/address');
        const protectedState=JSON.parse(runPhp('$pdo='+connect+';$q=$pdo->prepare("SELECT role,username,school_name,is_active FROM admin_account WHERE username=?");$q->execute(["'+role.toLowerCase()+'.test"]);echo json_encode($q->fetch(PDO::FETCH_ASSOC));'));
        check(protectedState.role===role && protectedState.username===role.toLowerCase()+'.test'
            && protectedState.school_name==='Test School' && Number(protectedState.is_active)===1,role+' protected fields unchanged');
        const image=runPhp('$im=imagecreatetruecolor(20,20);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);echo base64_encode($bytes);');
        const photo=new FormData();photo.set('csrf',session.csrf);photo.set('profile_photo',new Blob([Buffer.from(image,'base64')],{type:'image/png'}),'profile.png');
        const uploaded=await session.request('/php/admin/upload_profile_photo.php',photo,'POST','application/json');
        check(uploaded.status===200 && uploaded.data.photo_path.startsWith('uploads/admins/'),role+' can upload own photo');
        check((await session.request('/'+uploaded.data.photo_path)).status===200,role+' own photo is accessible');
        const accountManagement=await session.request(endpoint);
        check(accountManagement.status===403,role+' still cannot manage office accounts');
    }
    const adminProfile=await admin.request('/admin/profile.php');
    check(adminProfile.text.includes('value="Test Administrator"') && adminProfile.text.includes('data-open-office-accounts'), 'Office updates leave Admin profile and controls intact');
    const results=await registrar.request('/admin/results.php?q=Sample');
    check(results.status===200 && results.text.includes('Sample') && results.text.includes('data-student-upload-picker')
        && results.text.includes('data-grade-upload-picker'),'Registrar Results, search and upload UI');
    check((await osa.request('/admin/results.php')).status===403,'OSA denied direct Results access');
    check((await osa.request('/php/admin/upload_grades.php')).status===403,'OSA denied grade upload page');
    for(const route of ['/php/admin/upload_students.php','/php/admin/upload_grades.php','/php/admin/upload_students_zip.php']){
        check((await osa.request(route,{csrf:osa.csrf},'POST','application/json')).status===403,'OSA denied '+route);
    }
    check((await registrar.request('/php/admin/upload_grades.php')).status===200,'Registrar grade upload page');
    for(const route of ['/php/admin/upload_students.php','/php/admin/upload_grades.php']){
        check((await registrar.request(route,{csrf:'invalid'},'POST','application/json')).status===403,'Registrar upload requires CSRF');
    }
    const workbook=kind=>runPhp(String.raw`
require 'vendor/autoload.php';
$book=new PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet=$book->getActiveSheet();
if('@KIND@'==='students'){
    $sheet->fromArray([['student_number','first_name','last_name','course','year_level','section','password'],
        ['REG-UPLOADED','Imported','Student','BSIT',1,'A','Temporary-Student-Password']]);
}else{
    $sheet->setTitle('Subject Grades');
    $sheet->fromArray([['student_number','academic_year','semester','subject_code','subject_name','prelim_grade','midterm_grade','final_grade','overall_grade'],
        ['REG-UPLOADED','2026-2027','1st Semester','IT101','Computing',90,91,92,91]]);
}
ob_start();(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');echo base64_encode(ob_get_clean());
`.replaceAll('@KIND@',kind));
    for(const kind of ['students','grades']){
        const form=new FormData();form.set('csrf',registrar.csrf);
        form.set(kind==='students'?'student_excel':'grades_excel',
            new Blob([Buffer.from(workbook(kind),'base64')],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),'test.xlsx');
        const result=await registrar.request('/php/admin/upload_'+kind+'.php',form,'POST','application/json');
        check(result.status===200 && (kind!=='grades'||result.data?.ok),'Registrar imports '+kind+': '+result.text.slice(0,240));
    }
    check(runPhp('$pdo='+connect+';$s=$pdo->prepare("SELECT COUNT(*) FROM students WHERE student_number=?");$s->execute(["REG-UPLOADED"]);echo $s->fetchColumn();').trim()==='1','Registrar student import persisted');
    check(runPhp('$pdo='+connect+';$s=$pdo->prepare("SELECT COUNT(*) FROM subject_grades WHERE student_number=?");$s->execute(["REG-UPLOADED"]);echo $s->fetchColumn();').trim()==='1','Registrar grade import persisted');
}

async function browserTests(base, context) {
    const chrome = process.env.KNS_TEST_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if (!fs.existsSync(chrome)) throw new Error('Chrome required for --browser; set KNS_TEST_CHROME.');
    const chromeProfile = path.join(temporary, 'chrome');
    const browser = spawn(chrome, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--remote-debugging-port=0', '--user-data-dir=' + chromeProfile, 'about:blank'], {windowsHide: true, stdio: 'ignore'});
    let socket, next = 0;
    const pending = new Map(), errors = [];
    async function until(action, label, timeout = 15000) {
        const deadline = Date.now() + timeout;
        while (Date.now() < deadline) { if (await action()) return; await delay(80); }
        throw new Error('Timed out: ' + label);
    }
    const send = (method, params = {}) => new Promise((resolve,reject) => {
        const id = ++next;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
        pending.set(id, {resolve, reject, timeout});
        socket.send(JSON.stringify({id,method,params}));
    });
    const evaluate = async (fn, ...args) => {
        const result = await send('Runtime.evaluate', {expression: '(' + fn.toString() + ')(...' + JSON.stringify(args) + ')', awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.text + ' ' + result.exceptionDetails.exception?.description);
        return result.result.value;
    };
    const click = selector => evaluate(selector => { const button=document.querySelector(selector); button.focus(); button.click(); }, selector);
    const waitFor = (fn, label) => until(() => evaluate(fn), label);
    const screenshot = async name => {
        if (!process.env.KNS_TEST_ARTIFACTS) return;
        fs.mkdirSync(process.env.KNS_TEST_ARTIFACTS, {recursive:true});
        const {data} = await send('Page.captureScreenshot', {format:'png'});
        fs.writeFileSync(path.join(process.env.KNS_TEST_ARTIFACTS, name+'.png'), Buffer.from(data,'base64'));
    };
    try {
        let debugPort;
        await until(() => {
            try { debugPort = +fs.readFileSync(path.join(chromeProfile,'DevToolsActivePort'),'utf8').split('\n')[0]; return debugPort; }
            catch (error) { if (['ENOENT','EBUSY','EPERM'].includes(error.code)) return false; throw error; }
        }, 'Chrome debugger');
        const targets = await (await fetch('http://127.0.0.1:'+debugPort+'/json/list')).json();
        socket = new WebSocket(targets.find(target=>target.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        socket.onmessage = event => {
            const eventData = JSON.parse(event.data);
            if (eventData.id && pending.has(eventData.id)) {
                const promise = pending.get(eventData.id); pending.delete(eventData.id); clearTimeout(promise.timeout);
                if(eventData.error) promise.reject(new Error(eventData.error.message)); else promise.resolve(eventData.result);
            } else if(eventData.method==='Runtime.exceptionThrown') errors.push(eventData.params.exceptionDetails.text);
        };
        await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1672,height:941,deviceScaleFactor:1,mobile:false});
        for(const [name,value] of context.admin.cookies) await send('Network.setCookie',{name,value,url:base,path:'/'});
        await send('Page.navigate',{url:base+'/admin/dashboard.php?view=profile'});
        await waitFor(()=>!!document.querySelector('#kns-office-registration-form'),'Admin Profile');
        await waitFor(()=>[...document.querySelectorAll('link[rel=stylesheet]')].every(link=>!!link.sheet),'Styles');
        const layout = () => ['.sidebar-contianer','.header-main-contianer','.student-info-header','.profile'].map(selector=>{
            const box=document.querySelector(selector).getBoundingClientRect();return [box.x,box.y,box.width,box.height];
        });
        const before = await evaluate(layout);
        await evaluate(()=>{
            window.__accountFetch=window.fetch;
            window.fetch=async (...args)=>{
                if(String(args[0]).includes('office-accounts.php')) await new Promise(resolve=>window.__releaseAccounts=resolve);
                return window.__accountFetch(...args);
            };
        });
        await click('[data-open-office-accounts]');
        check(await evaluate(()=>document.querySelector('#kns-office-accounts tbody').textContent.includes('Loading')), 'UI loading state');
        await evaluate(()=>{window.fetch=window.__accountFetch;window.__releaseAccounts();});
        await waitFor(()=>document.querySelectorAll('tr[data-office-account-id]').length===2,'Real list rows');
        check(await evaluate(()=>document.querySelector('#kns-office-accounts tbody').textContent.includes('Phone: 09123456789')
            && document.querySelector('#kns-office-accounts tbody').textContent.includes('Username: osa.test')), 'UI lists phone, email and username');
        await screenshot('office-accounts');
        await click('[data-close-office-accounts]');
        await click('[data-open-office-registration]');
        check(await evaluate(()=>[...document.querySelector('#kns-office-role').options].map(option=>option.value).join(',')==='Registrar,OSA'), 'Only allowed office roles shown');
        const modal = await evaluate(()=>{
            const d=document.querySelector('#kns-office-registration'),r=d.getBoundingClientRect();
            return {width:r.width,height:r.height,x:r.x,viewport:document.documentElement.clientWidth,
                scroll:d.scrollHeight>d.clientHeight,passwords:getComputedStyle(d.querySelector('.kns-office-registration-passwords')).gridTemplateColumns};
        });
        check(modal.width===556 && Math.abs(modal.x+modal.width/2-modal.viewport/2)<2,'Registration original size and centering');
        await screenshot('office-registration');
        await send('Emulation.setDeviceMetricsOverride',{width:1024,height:640,deviceScaleFactor:1,mobile:false});
        check(await evaluate(()=>{const d=document.querySelector('#kns-office-registration');return d.scrollHeight>d.clientHeight&&d.getBoundingClientRect().height<=608;}),'Registration scrolls on shorter screens');
        await send('Emulation.setDeviceMetricsOverride',{width:1672,height:941,deviceScaleFactor:1,mobile:false});
        await evaluate(password=>{
            const form=document.querySelector('#kns-office-registration-form');
            const fields={full_name:'Browser Staff',username:'browser.staff',email:'browser@example.test',phone_number:'09123456780',role:'OSA',password,password_confirmation:password};
            for(const [name,value] of Object.entries(fields))form.elements.namedItem(name).value=value;
            form.requestSubmit();
        }, officePassword);
        await waitFor(()=>document.querySelector('#kns-office-accounts').open && document.querySelectorAll('tr[data-office-account-id]').length===3,'Browser account creation refresh');
        check(await evaluate(()=>document.querySelector('[data-office-accounts-message]').textContent.includes('created')
            && document.querySelector('#kns-office-password').value===''),'Creation success message and cleared passwords');
        await click('tr[data-office-account-id] .kns-office-accounts-row-delete');
        check(await evaluate(()=>document.querySelector('[data-selected-office-account-name]').textContent==='Browser Staff'
            && document.querySelector('[data-selected-office-account-role]').textContent==='OSA'),'Selected account confirmation');
        await screenshot('office-confirmation');
        await evaluate(()=>{
            const form=document.querySelector('#kns-office-deactivation-form');
            form.elements.admin_password.value='wrong';form.requestSubmit();
        });
        await waitFor(()=>document.querySelector('[data-office-deactivation-message]').textContent.includes('incorrect'),'Wrong Admin password feedback');
        check(await evaluate(()=>document.querySelector('#kns-office-account-delete').open),'Failed password keeps confirmation open');
        await evaluate(password=>{
            const form=document.querySelector('#kns-office-deactivation-form');form.elements.admin_password.value=password;form.requestSubmit();
        }, adminPassword);
        await waitFor(()=>!document.querySelector('#kns-office-account-delete').open && document.querySelector('[data-office-accounts-message]').textContent.includes('deactivated')
           ,'Deactivation UI refresh');
        await waitFor(()=>document.querySelector('tr[data-office-account-id] .kns-office-accounts-row-delete')?.disabled,'Inactive row button disabled');
        check(await evaluate(()=>document.querySelector('tr[data-office-account-id]').textContent.includes('Inactive')),'Inactive status shown');
        await screenshot('office-deactivated');
        await click('[data-close-office-accounts]');
        await evaluate(()=>{window.fetch=async(...args)=>String(args[0]).includes('office-accounts.php')?Promise.reject(new Error('Test connection interrupted')):window.__accountFetch(...args);});
        await click('[data-open-office-accounts]');
        await waitFor(()=>!!document.querySelector('.kns-office-accounts-retry'),'List error and retry state');
        await evaluate(()=>window.fetch=window.__accountFetch);
        await click('.kns-office-accounts-retry');
        await waitFor(()=>document.querySelectorAll('tr[data-office-account-id]').length===3,'List retry');
        await click('[data-close-office-accounts]');
        check(JSON.stringify(before)===JSON.stringify(await evaluate(layout)),'Surrounding UI layout unchanged');
        check(errors.length===0,'No browser JavaScript exceptions');
        const previewAccount=await context.admin.request(endpoint,{action:'create',csrf:context.admin.csrf,
            full_name:'Registrar Preview',username:'registrar.preview',email:'registrar.preview@example.test',
            phone_number:'09123456780',role:'Registrar',password:officePassword,password_confirmation:officePassword});
        check(previewAccount.status===201,'Create browser Registrar fixture');
        const previewRegistrar=new BrowserSession(base);
        await previewRegistrar.login('registrar.preview',officePassword);
        for(const [role,session] of [['Registrar',previewRegistrar],['OSA',context.osa]]){
            await send('Network.clearBrowserCookies');
            for(const[name,value]of session.cookies)await send('Network.setCookie',{name,value,url:base,path:'/'});
            await send('Page.navigate',{url:base+'/admin/dashboard.php?view=profile'});
            await waitFor(()=>!!document.querySelector('#admin-profile-details'),'Office profile');
            check(await evaluate(role=>document.querySelector('.student-info-header h1').textContent===role+' Profile'
                && document.querySelector('.academic-text h2').textContent==='Office Information'
                && !document.querySelector('[data-open-office-registration]')&&!document.querySelector('[data-open-office-accounts]')
                && !document.querySelector('#kns-office-registration'),role),role+' browser profile has no account controls');
            check(await evaluate(()=>document.querySelector('#admin-profile-school').readOnly),'School name protected in office profile');
            check(JSON.stringify(before)===JSON.stringify(await evaluate(layout)),role+' profile reuses original Admin layout');
            await waitFor(()=>[...document.images].every(img=>img.complete),'Office profile images');
            await screenshot(role.toLowerCase()+'-profile');
            check(await evaluate(()=>[...document.querySelectorAll('#admin-profile-details input,#admin-profile-details textarea')].every(input=>input.readOnly)),'Office information card is read-only');
            await click('[data-settings-gear]');
            await click('[data-settings-open="profile"]');
            await waitFor(()=>document.querySelector('.ks-modal').open&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false','Office profile settings');
            await evaluate(role=>{
                const form=document.querySelector('[data-settings-action="profile"]');
                form.elements.full_name.value=role+' Browser Updated';form.requestSubmit();
            },role);
            await waitFor(()=>document.querySelector('[data-settings-feedback]').textContent.includes('saved')&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false','Office settings browser save');
            await click('[data-settings-close]');
            check(await evaluate(role=>document.querySelector('#admin-profile-name').value===role+' Browser Updated',role),'Read-only office details updated from settings');
            await click('.overview-button');
            await waitFor(()=>!!document.querySelector('.kns-overview'),'Office Overview');
            check(await evaluate(()=>!!document.querySelector('[data-create-post]') && !!document.querySelector('[data-post-form]')),role+' full Overview browser tools');
            await click('[data-create-post]');
            check(await evaluate(()=>document.querySelector('.kns-post-modal').open),role+' Create Post opens');
            await click('[data-close-post]');
            if(role==='Registrar'){
                await click('.results-button');
                await waitFor(()=>!!document.querySelector('#student-upload-form'),'Registrar Results');
                check(await evaluate(()=>!!document.querySelector('[data-student-upload-picker]')&&!!document.querySelector('[data-grade-upload-picker]')),'Registrar browser includes both upload tools');
                await screenshot('registrar-results');
            }else{
                check(await evaluate(()=>!document.querySelector('.results-button')),'OSA browser has no Results navigation');
            }
            await click('.may-request-button');
            await waitFor(()=>!!document.querySelector('.request-management'),'Office requests');
            check(await evaluate(()=>!!document.querySelector('.request-management')),role+' browser Requests access');
        }
        console.log('Browser role access, office profiles, and existing account modals passed.');
    } catch(error) {
        console.error(error.message);
        await screenshot('office-test-failure');
        throw error;
    } finally {
        socket?.close();
        if(browser.exitCode===null) {
            const stopped=new Promise(resolve=>browser.once('exit',resolve));
            browser.kill(); await Promise.race([stopped,delay(3000)]);
        }
    }
}
(async()=>{
    try {
        setup();
        const serverPort=await port(), base='http://127.0.0.1:'+serverPort+'/kns_portal';
        server=spawn(php,['-S','127.0.0.1:'+serverPort,'-t',temporary,path.join(temporary,'router.php')],{cwd:root,windowsHide:true,stdio:['ignore','ignore','pipe']});
        let log='';server.stderr.on('data',data=>{log+=data.toString();});
        for(let attempt=0;attempt<60;attempt++) {
            try { await fetch(base+'/login.php');break; }catch{await delay(100);}
        }
        let context;
        try { context=await httpTests(base); }
        catch(error) { if(log.includes('Fatal error')) console.error(log.match(/.*Fatal error.*/g)?.join('\n'));throw error; }
        console.log('HTTP authentication, authorization, CSRF, registration, and history-preserving deactivation passed.');
        if(process.argv.includes('--browser')) await browserTests(base,context);
        console.log('Passed '+checks+' integration checks using an isolated database.');
    } finally {
        if(server&&server.exitCode===null) {
            const stopped=new Promise(resolve=>server.once('exit',resolve));server.kill();
            await Promise.race([stopped,delay(3000)]);
        }
        if(!/^kns_office_accounts_test_[a-f0-9]{12}$/.test(database))throw new Error('Unexpected test database name.');
        runPhp("require 'db.php';$pdo->exec('DROP DATABASE IF EXISTS "+database+"');");
        const resolved=path.resolve(temporary);
        if(path.dirname(resolved)!==path.resolve(os.tmpdir())||!path.basename(resolved).startsWith('kns-office-dynamic-'))throw new Error('Unexpected temporary directory.');
        if(process.platform==='win32') {
            // Native removal handles read-only directory attributes copied by Windows.
            const cleanup = "$ErrorActionPreference='Stop'\n"
                + "$target=[IO.Path]::GetFullPath($env:KNS_OFFICE_TEST_TEMP)\n"
                + "$base=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\\')\n"
                + "if([IO.Directory]::GetParent($target).FullName -ne $base -or [IO.Path]::GetFileName($target) -notmatch '^kns-office-dynamic-[A-Za-z0-9]{6}$'){throw 'Unexpected test path'}\n"
                + "Remove-Item -LiteralPath $target -Recurse -Force\n";
            execFileSync('powershell.exe',['-NoProfile','-NonInteractive','-Command','-'],{
                input:cleanup,env:{...process.env,KNS_OFFICE_TEST_TEMP:resolved},windowsHide:true,stdio:['pipe','pipe','pipe']});
        } else fs.rmSync(resolved,{recursive:true,force:true,maxRetries:3,retryDelay:200});
    }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
