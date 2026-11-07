param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference = 'Stop'
$config = Get-Content -Raw -LiteralPath $ConfigPath | ConvertFrom-Json
$script:cdpSocket = $null
$script:cdpId = 0
$script:browserErrors = [System.Collections.Generic.List[string]]::new()
$script:checks = 0
function Check-Browser([bool]$Condition, [string]$Label) {
    if (-not $Condition) { throw $Label }
    $script:checks++
}
function Invoke-Cdp([string]$Method, $Params = @{}) {
    $script:cdpId++; $id = $script:cdpId
    $json = @{id=$id;method=$Method;params=$Params} | ConvertTo-Json -Depth 20 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $timeout = [System.Threading.CancellationTokenSource]::new(15000)
    try {
        $task = $script:cdpSocket.SendAsync([System.ArraySegment[byte]]::new($bytes), [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $timeout.Token)
        [void]$task.GetAwaiter().GetResult()
        while ($true) {
            $memory = [System.IO.MemoryStream]::new()
            try {
                do {
                    $buffer = New-Object byte[] 32768
                    $task = $script:cdpSocket.ReceiveAsync([System.ArraySegment[byte]]::new($buffer), $timeout.Token)
                    $received = $task.GetAwaiter().GetResult()
                    if ($received.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) { throw 'Browser connection closed.' }
                    $memory.Write($buffer, 0, $received.Count)
                } until ($received.EndOfMessage)
                $response = [System.Text.Encoding]::UTF8.GetString($memory.ToArray()) | ConvertFrom-Json
            } finally { $memory.Dispose() }
            if ($response.method -eq 'Runtime.exceptionThrown') { $script:browserErrors.Add($response.params.exceptionDetails.text) }
            if ($response.id -eq $id) {
                if ($response.error) { throw $response.error.message }
                return $response.result
            }
        }
    } finally { $timeout.Dispose() }
}
function Invoke-Js([string]$Expression) {
    $result = Invoke-Cdp 'Runtime.evaluate' @{expression=$Expression;awaitPromise=$true;returnByValue=$true}
    if ($result.exceptionDetails) { throw ($result.exceptionDetails.text + ' ' + $result.exceptionDetails.exception.description) }
    return $result.result.value
}
function Wait-Js([string]$Expression, [string]$Label) {
    $deadline = [DateTime]::UtcNow.AddSeconds(15)
    do { if (Invoke-Js $Expression) { return }; Start-Sleep -Milliseconds 100 } while ([DateTime]::UtcNow -lt $deadline)
    throw ('Timed out: ' + $Label)
}
function Save-TermScreenshot([string]$Name) {
    $result = Invoke-Cdp 'Page.captureScreenshot' @{format='png'}
    [System.IO.File]::WriteAllBytes((Join-Path $config.artifacts ($Name + '.png')), [Convert]::FromBase64String($result.data))
}

$browser=$null
$profile=Join-Path $config.temporary ('student-chrome-'+[guid]::NewGuid().ToString('N'))
$script:sockets=[System.Collections.Generic.List[System.Net.WebSockets.ClientWebSocket]]::new()
function Wait-Js([string]$Expression,[string]$Label) {
    $deadline=[DateTime]::UtcNow.AddSeconds(20)
    do { try { if(Invoke-Js $Expression){return} } catch {}; Start-Sleep -Milliseconds 100 } while([DateTime]::UtcNow-lt $deadline)
    throw ('Timed out: '+$Label)
}
try {
    $browser=Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',('--user-data-dir='+$profile),'about:blank') -WindowStyle Hidden -PassThru
    $portFile=Join-Path $profile 'DevToolsActivePort'
    $deadline=[DateTime]::UtcNow.AddSeconds(20)
    while(-not(Test-Path -LiteralPath $portFile)){if([DateTime]::UtcNow-gt $deadline){throw 'Chrome did not start'};Start-Sleep -Milliseconds 100}
    $port=[int](Get-Content -LiteralPath $portFile -TotalCount 1)
    function New-StudentTab([string]$Number,[string]$Password) {
        $target=Invoke-RestMethod -Method Put -Uri ('http://127.0.0.1:'+$port+'/json/new?'+[Uri]::EscapeDataString($config.base+'/login.php'))
        $socket=[System.Net.WebSockets.ClientWebSocket]::new()
        [void]$socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[System.Threading.CancellationToken]::None).GetAwaiter().GetResult()
        $script:sockets.Add($socket);$script:cdpSocket=$socket
        [void](Invoke-Cdp 'Page.enable');[void](Invoke-Cdp 'Runtime.enable');[void](Invoke-Cdp 'DOM.enable')
        [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1100;deviceScaleFactor=1;mobile=$false})
        Wait-Js "!!document.querySelector('.left-form [name=kns_tab]')" 'login tab context'
        $numberJson=ConvertTo-Json -Compress $Number;$passwordJson=ConvertTo-Json -Compress $Password
        [void](Invoke-Js ("document.querySelector('.left-form [name=student_number]').value="+$numberJson+";document.querySelector('.left-form [name=password]').value="+$passwordJson+";document.querySelector('.left-form').requestSubmit()"))
        Wait-Js "location.pathname.endsWith('/dashboard/dashboard.php')&&typeof loadPage==='function'" 'student dashboard'
        [void](Invoke-Js "loadPage('../module/studentInformation.php')")
        Wait-Js "!!document.querySelector('[data-student-settings][data-initialized]')" 'student settings initialized'
        return $socket
    }
    function Open-Settings([string]$Panel) {
        [void](Invoke-Js ("document.querySelector('[data-settings-gear]').click();document.querySelector('[data-settings-open="+$Panel+"]').click()"))
        Wait-Js ("document.querySelector('.ks-modal').open&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'&&!document.querySelector('[data-settings-panel="+$Panel+"]').hidden") ($Panel+' settings')
        Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-feedback]').dataset.error!=='true'")) ($Panel+' load error')
    }
    function Close-Settings {
        [void](Invoke-Js "document.querySelector('[data-settings-close]').click()")
        Wait-Js "!document.querySelector('.ks-modal').open&&document.activeElement===document.querySelector('[data-settings-gear]')" 'settings focus return'
    }
    function Submit-Settings([string]$Action,[hashtable]$Values) {
        $json=ConvertTo-Json -Compress $Values
        [void](Invoke-Js ("(()=>{const f=document.querySelector('[data-settings-action="+$Action+"]'),v="+$json+";for(const[k,x]of Object.entries(v)){const e=f.elements[k];if(e.type==='checkbox')e.checked=x;else e.value=x;}f.requestSubmit();})()"))
        Wait-Js "document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'&&!document.querySelector('[data-settings-feedback]').hidden" ($Action+' response')
    }
    $first=New-StudentTab 'SETTINGS-ALPHA' $config.password
    Check-Browser ([bool](Invoke-Js "document.querySelectorAll('[data-settings-open]').length===7")) 'All seven student settings sections'
    $firstKey=Invoke-Js "new URL(location.href).searchParams.get('kns_tab')"
    Open-Settings 'profile'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[name=full_name]').readOnly&&document.querySelector('[data-settings-student-number]').value==='SETTINGS-ALPHA'&&document.querySelector('[data-settings-role]').value==='Student'")) 'Student identity is read-only'
    Close-Settings
    Open-Settings 'contacts'
    Submit-Settings 'contacts' @{phone_number='09441234567';alternate_contact='09551234567';address='Browser fixture address, Subic'}
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-feedback]').dataset.error==='false'&&document.querySelector('[data-student-profile=contact]').textContent==='09441234567'&&document.querySelector('[data-student-profile=address]').textContent==='Browser fixture address, Subic'")) 'Contact changes save and update profile'
    Close-Settings
    Open-Settings 'email'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-current-email]').value==='verified@example.invalid'&&!document.querySelector('[data-settings-action=verify_email]').hidden")) 'Current email stays unchanged while verification is pending'
    Submit-Settings 'verify_email' @{verification_code='987654'}
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-student-profile=email]').textContent==='browser-verified@example.invalid'&&document.querySelector('[data-settings-current-email]').value==='browser-verified@example.invalid'")) 'Verified email updates student profile'
    Close-Settings
    Open-Settings 'security'
    Submit-Settings 'security' @{current_password='wrong';new_password='Student-browser-password-111';password_confirmation='Student-browser-password-111'}
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-feedback]').dataset.error==='true'")) 'Wrong current password is rejected'
    Check-Browser ([bool](Invoke-Js "[...document.querySelectorAll('[data-settings-action=security] .ks-password input')].every(x=>!x.value)")) 'Password fields cleared after rejection'
    Submit-Settings 'security' @{current_password=$config.password;new_password='Student-browser-password-111';password_confirmation='Student-browser-password-111'}
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-feedback]').dataset.error==='false'")) 'Password update succeeds'
    Check-Browser ([bool](Invoke-Js "fetch('../php/student/settings.php',{headers:{Accept:'application/json'}}).then(r=>r.status===200)")) 'Password change preserves the student session'
    Close-Settings
    Open-Settings 'appearance'
    Submit-Settings 'appearance' @{theme='dark'}
    Check-Browser ([bool](Invoke-Js "document.documentElement.dataset.studentTheme==='dark'&&getComputedStyle(document.querySelector('.student-information')).backgroundColor==='rgb(27, 41, 33)'")) 'Dark mode applies to student profile'
    Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.bell-logo')).backgroundColor==='rgba(0, 0, 0, 0)'&&getComputedStyle(document.querySelector('.profile-status span')).color==='rgb(179, 242, 202)'")) 'Dark bell and account status contrast'
    Close-Settings
    Open-Settings 'language'
    Submit-Settings 'language' @{language='fil'}
    Check-Browser ([bool](Invoke-Js "document.documentElement.lang==='fil'&&document.querySelector('[data-settings-gear]').title==='Mga Setting'&&document.querySelector('.student-name h1').textContent.trim()==='Fixture Alpha'")) 'Filipino UI preserves student identity'
    Close-Settings
    Open-Settings 'notifications'
    Submit-Settings 'notifications' @{notify_announcements=$false;notify_requests=$false;notify_activity=$false;notify_email=$false}
    Check-Browser ([bool](Invoke-Js "fetch('../php/student/notifications.php').then(r=>r.json()).then(d=>d.ok&&d.total===0&&d.unread_count===0)")) 'Notification preferences take effect'
    Close-Settings
    [void](Invoke-Js "document.querySelector('[data-settings-gear]').click()")
    Save-TermScreenshot 'student-settings-dark-menu'
    [void](Invoke-Js "document.querySelector('[data-settings-gear]').click()")
    Open-Settings 'contacts'
    Save-TermScreenshot 'student-settings-dark-contacts'
    Close-Settings
    [void](Invoke-Cdp 'Page.reload')
    Wait-Js "document.readyState==='complete'&&document.documentElement.dataset.studentTheme==='dark'&&document.documentElement.lang==='fil'&&typeof loadPage==='function'" 'saved student presentation'
    [void](Invoke-Js "loadPage('../module/studentInformation.php')")
    Wait-Js "!!document.querySelector('[data-student-settings][data-initialized]')" 'profile after reload'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-student-profile=contact]').textContent==='09441234567'&&document.querySelector('[data-student-profile=email]').textContent==='browser-verified@example.invalid'")) 'Student changes survive reload'
    [void](Invoke-Js "loadPage('../module/overview.php')")
    Wait-Js "!!document.querySelector('.student-announcements .post-content')" 'dark student announcements'
    Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.student-announcements .post-content')).backgroundColor==='rgb(27, 41, 33)'&&document.querySelector('.description-text').textContent==='Authored announcement content'")) 'Announcements use dark surfaces and preserve authored text'
    Check-Browser ([bool](Invoke-Js "(()=>{const title=document.querySelector('.greet-layer').getBoundingClientRect(),hero=document.querySelector('.greeting-contianer').getBoundingClientRect();return title.bottom<=hero.bottom;})()")) 'Filipino greeting is fully visible'
    Save-TermScreenshot 'student-overview-dark'
    $second=New-StudentTab 'SETTINGS-BETA' $config.other_password
    Check-Browser ((Invoke-Js "new URL(location.href).searchParams.get('kns_tab')") -ne $firstKey) 'Separate student tab context'
    Check-Browser ([bool](Invoke-Js "document.documentElement.dataset.studentTheme==='light'&&document.documentElement.lang==='en'&&document.querySelector('[data-student-profile=contact]').textContent==='09171234567'")) 'Other student keeps own contact details and preferences'
    Open-Settings 'profile'
    Save-TermScreenshot 'student-settings-light-profile'
    Close-Settings
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$false})
    Open-Settings 'contacts'
    Check-Browser ([bool](Invoke-Js "(()=>{const r=document.querySelector('.ks-modal').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight;})()")) 'Student settings fit a narrow viewport'
    Save-TermScreenshot 'student-settings-mobile'
    Close-Settings
    $script:cdpSocket=$first
    [void](Invoke-Js "document.querySelector('form.logout-contianer').requestSubmit()")
    Wait-Js "location.pathname.endsWith('/login.php')&&!!document.querySelector('.left-form')" 'first student logout'
    $script:cdpSocket=$second
    Check-Browser ([bool](Invoke-Js "fetch('../php/student/settings.php').then(r=>r.json()).then(d=>d.ok&&d.state.profile.student_number==='SETTINGS-BETA')")) 'Other student stays signed in after logout'
    Check-Browser ($script:browserErrors.Count-eq 0) ('Browser exceptions: '+($script:browserErrors-join '; '))
    Write-Output ('PASS: '+$script:checks+' student Settings browser checks across two student tabs. Screenshots: '+$config.artifacts)
} catch {
    Write-Output ('Browser exceptions: '+($script:browserErrors -join '; '))
    try { [System.IO.File]::WriteAllText((Join-Path $config.artifacts 'failure-errors.txt'),($script:browserErrors -join '; ')); Write-Output (Invoke-Js "JSON.stringify({url:location.href,settings:typeof window.KNSStudentSettings,initialized:document.querySelector('[data-student-settings]')?.dataset.initialized,scripts:[...document.scripts].map(s=>s.src)})"); Save-TermScreenshot 'student-settings-failure' } catch {}
    throw
} finally {
    if($script:cdpSocket){try{[void](Invoke-Cdp 'Browser.close')}catch{}}
    foreach($socket in $script:sockets){$socket.Dispose()}
    if($browser){try{[void]$browser.WaitForExit(5000)}catch{}}
    $profileLeaf=Split-Path -Leaf $config.temporary
    Get-CimInstance Win32_Process -Filter "Name='chrome.exe'"|Where-Object{$_.CommandLine-and $_.CommandLine.Contains($profileLeaf)}|ForEach-Object{Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue}
}
