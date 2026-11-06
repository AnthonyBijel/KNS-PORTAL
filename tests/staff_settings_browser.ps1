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
$browser = $null
try {
    $profile = Join-Path $config.temporary 'chrome'
    $browser = Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',('--user-data-dir=' + $profile),'about:blank') -WindowStyle Hidden -PassThru
    $portFile = Join-Path $profile 'DevToolsActivePort'
    $deadline = [DateTime]::UtcNow.AddSeconds(15)
    while (-not (Test-Path -LiteralPath $portFile)) {
        if ([DateTime]::UtcNow -gt $deadline) { throw 'Chrome debugger did not start.' }
        Start-Sleep -Milliseconds 100
    }
    $port = [int](Get-Content -LiteralPath $portFile -TotalCount 1)
    $targets = Invoke-RestMethod -Uri ('http://127.0.0.1:' + $port + '/json/list')
    $target = $targets | Where-Object type -eq 'page' | Select-Object -First 1
    $script:cdpSocket = [System.Net.WebSockets.ClientWebSocket]::new()
    $task = $script:cdpSocket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [System.Threading.CancellationToken]::None)
    [void]$task.GetAwaiter().GetResult()
    [void](Invoke-Cdp 'Page.enable'); [void](Invoke-Cdp 'Runtime.enable'); [void](Invoke-Cdp 'Network.enable')
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1808;height=870;deviceScaleFactor=1;mobile=$false})
    function Set-StaffAccount([string]$Name) {
        [void](Invoke-Cdp 'Network.clearBrowserCookies')
        foreach($cookie in $config.cookies.$Name) {
            $parts=$cookie -split '=',2
            [void](Invoke-Cdp 'Network.setCookie' @{name=$parts[0];value=$parts[1];url=$config.base;path='/'})
        }
        [void](Invoke-Cdp 'Page.navigate' @{url=($config.base+'/admin/dashboard.php?view=profile')})
        $role=@{admin='Administrator';registrar='Registrar';osa='OSA'}[$Name]
        Wait-Js ("!!document.querySelector('[data-staff-settings][data-initialized]')&&document.querySelector('#main-content-contianer').dataset.staffRole==='"+$role+"'") ($Name+' profile')
        Wait-Js "[...document.querySelectorAll('link[rel=stylesheet]')].every(x=>!!x.sheet)" 'Profile styles'
    }
    function Open-Settings([string]$Panel) {
        [void](Invoke-Js ("document.querySelector('[data-settings-gear]').focus();document.querySelector('[data-settings-gear]').click();document.querySelector('[data-settings-open="+$Panel+"]').click()"))
        Wait-Js ("document.querySelector('.ks-modal').open&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'&&!document.querySelector('[data-settings-panel="+$Panel+"]').hidden") ($Panel+' popup loaded')
        Check-Browser ([bool](Invoke-Js "!document.querySelector('[data-settings-feedback]').dataset.error||document.querySelector('[data-settings-feedback]').dataset.error==='false'")) ($Panel+' failed to load')
    }
    function Close-Settings {
        [void](Invoke-Js "document.querySelector('[data-settings-close]').click()")
        Wait-Js "!document.querySelector('.ks-modal').open&&document.activeElement===document.querySelector('[data-settings-gear]')" 'Close and restore focus'
    }
    foreach($name in @('admin','registrar','osa')) {
        Set-StaffAccount $name
        Check-Browser ([bool](Invoke-Js "!document.querySelector('#admin-profile-form')&&[...document.querySelector('#admin-profile-details').childNodes].every(n=>n.nodeType!==3||!n.textContent.trim())&&[...document.querySelectorAll('#admin-profile-details input,#admin-profile-details textarea')].every(n=>n.readOnly)&&!document.querySelector('.admin-profile-actions')")) ($name+' information card must be read-only')
        $geometry='JSON.stringify([".sidebar-contianer",".header-main-contianer",".student-info-header",".admin-profile>.profile",".student-information"].map(s=>{const r=document.querySelector(s).getBoundingClientRect();return [r.x,r.y,r.width,r.height];}))'
        $before=Invoke-Js $geometry
        [void](Invoke-Js "document.querySelector('[data-settings-gear]').focus();document.querySelector('[data-settings-gear]').click()")
        Check-Browser ([bool](Invoke-Js "document.querySelectorAll('.ks-menu [role=menuitem]').length===7&&!document.querySelector('.ks-menu').textContent.toLowerCase().includes('sign out')")) ($name+' seven options only')
        Check-Browser ([bool](Invoke-Js "(()=>{const r=document.querySelector('.ks-menu').getBoundingClientRect();return r.width<=250&&r.left>=0&&r.right<=innerWidth;})()")) 'Compact settings menu positioning'
        Save-TermScreenshot ('staff-'+$name+'-settings-menu')
        [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='ArrowDown';code='ArrowDown';windowsVirtualKeyCode=40})
        Check-Browser ([bool](Invoke-Js "document.activeElement.dataset.settingsOpen==='notifications'")) 'Arrow key menu navigation'
        [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Escape';code='Escape';windowsVirtualKeyCode=27})
        Wait-Js "document.querySelector('.ks-menu').hidden" 'Escape closes menu'
        foreach($panel in @('profile','notifications','security','appearance','language','email','contacts')) {
            Open-Settings $panel
            Check-Browser ([bool](Invoke-Js "document.querySelector('.ks-modal').matches(':modal')&&document.querySelector('[data-settings-school]').textContent==='Fixture School'")) ($name+' real school/modal')
            Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.ks-modal'),r=d.getBoundingClientRect();return r.width===420&&r.height<innerHeight;})()")) ($panel+' compact modal')
            if($panel -eq 'profile') {
                Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-role]').readOnly&&document.querySelector('[data-settings-school-input]').readOnly===(document.querySelector('[data-settings-role]').value!=='School Administrator')")) 'School editing must follow role permission'
            }
            if($name -eq 'admin') { Save-TermScreenshot ('staff-settings-'+$panel) }
            Close-Settings
        }
        Check-Browser ((Invoke-Js $geometry) -eq $before) ($name+' surrounding layout changed')
    }
    # Presentation must follow the signed-in account across every permitted staff page.
    foreach($name in @('admin','registrar','osa')) {
        Set-StaffAccount $name
        Check-Browser ([bool](Invoke-Js "document.documentElement.dataset.staffTheme==='light'&&document.documentElement.lang==='en'")) ($name+' preferences leaked from another account')
        Open-Settings 'appearance'
        [void](Invoke-Js "var f=document.querySelector('[data-settings-action=appearance]');f.elements.theme.value='dark';f.requestSubmit()")
        Wait-Js "document.documentElement.dataset.staffTheme==='dark'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Save global dark mode'
        Close-Settings
        Open-Settings 'language'
        [void](Invoke-Js "var f=document.querySelector('[data-settings-action=language]');f.elements.language.value='fil';f.requestSubmit()")
        Wait-Js "document.documentElement.lang==='fil'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Save global Filipino'
        Close-Settings
        [void](Invoke-Js "loadPage('overview.php')")
        Wait-Js "!!document.querySelector('[data-post-search]')&&document.querySelector('[data-post-search]').placeholder==='Maghanap ng mga post...'" 'Overview language after navigation'
        Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-feed-heading').textContent==='Mga Anunsyo'&&getComputedStyle(document.querySelector('.kns-stat-card')).backgroundColor==='rgb(27, 41, 33)'&&document.querySelector('.kns-post-copy h3').textContent==='Fixture notice'")) ($name+' overview theme/language or authored content changed')
        Save-TermScreenshot ('staff-'+$name+'-overview-filipino-dark')
        [void](Invoke-Js "document.querySelector('.kns-post-copy h3').textContent='Overview'")
        [void](Invoke-Js 'new Promise(resolve=>queueMicrotask(resolve))')
        Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-post-copy h3').textContent==='Overview'")) 'Authored text matching a UI label must remain unchanged'
        [void](Invoke-Js "document.querySelector('[data-create-post]').click()")
        Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-post-modal').matches(':modal')&&getComputedStyle(document.querySelector('.kns-post-modal')).backgroundColor==='rgb(27, 41, 33)'&&document.querySelector('#kns-create-title').textContent==='Gumawa ng Post'")) 'Create post popup must inherit theme/language'
        [void](Invoke-Js "document.querySelector('.kns-post-modal').close();loadPage('requests.html')")
        Wait-Js "!!document.querySelector('.request-eye-btn')&&document.querySelector('.request-header h1').textContent==='Mga Kahilingan ng Estudyante'" 'Requests language after navigation'
        Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.request-filters')).backgroundColor==='rgb(36, 53, 43)'&&getComputedStyle(document.querySelector('.request-table-card')).backgroundColor==='rgb(27, 41, 33)'&&[...document.querySelector('#request-status').options].some(o=>o.value==='Pending'&&o.textContent==='Naghihintay')")) 'Request status translation changed filter values or dark palette'
        [void](Invoke-Js "document.querySelector('.request-eye-btn').click()")
        Wait-Js "document.querySelector('#requestDetailsModal').classList.contains('is-open')&&document.querySelector('[data-request-field=student_name]').textContent==='Fixture Student'" 'Request details loaded'
        Check-Browser ([bool](Invoke-Js "document.querySelector('#rdTitle').textContent==='Mga Detalye ng Kahilingan'&&getComputedStyle(document.querySelector('.rd-card')).backgroundColor==='rgb(27, 41, 33)'")) 'Request details theme/language'
        [void](Invoke-Js "document.querySelector('.rd-messenger').click()")
        Wait-Js "!!document.querySelector('.rm-panel:not([hidden])')" 'Request messenger opened'
        Check-Browser ([bool](Invoke-Js "document.querySelector('.rm-input').placeholder.startsWith('Sumulat ng mensahe')&&getComputedStyle(document.querySelector('.rm-panel')).backgroundColor==='rgb(27, 41, 33)'")) 'Messenger language/theme'
        Save-TermScreenshot ('staff-'+$name+'-request-filipino-dark')
        [void](Invoke-Js "document.querySelector('.rd-close').click()")
        if($name -ne 'osa') {
            [void](Invoke-Js "loadPage('results.php')")
            Wait-Js "!!document.querySelector('.results-main-table')&&document.querySelector('.results-main-table-header h1').textContent==='Listahan ng Estudyante'" 'Results language after navigation'
            Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.results-main-table')).backgroundColor==='rgb(27, 41, 33)'&&document.querySelector('[data-list-open]').textContent.includes('I-download ang Listahan')")) 'Results theme/language'
            [void](Invoke-Js "document.querySelector('[data-list-open]').click()")
            Wait-Js "document.querySelector('.kns-list-modal').open&&document.querySelector('.kns-list-modal h2').textContent==='I-download ang Listahan ng Estudyante'" 'Download popup language'
            Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.kns-list-filters')).backgroundColor==='rgb(36, 53, 43)'&&getComputedStyle(document.querySelector('.kns-list-field label')).color==='rgb(237, 245, 239)'&&getComputedStyle(document.querySelector('.kns-list-modal')).backgroundColor==='rgb(27, 41, 33)'")) 'Download popup dark mode'
            Save-TermScreenshot ('staff-'+$name+'-results-filipino-dark')
            [void](Invoke-Js "document.querySelector('.kns-list-modal').close()")
            [void](Invoke-Js "document.querySelector('[data-terms-open]').click()")
            Wait-Js "document.querySelector('.kns-terms-modal').open&&document.querySelector('.kns-terms-title').textContent==='Pamahalaan ang mga Terminong Akademiko'" 'Term popup language'
            Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.kns-terms-modal')).backgroundColor==='rgb(27, 41, 33)'&&getComputedStyle(document.querySelector('.kns-terms-note')).backgroundColor==='rgb(36, 53, 43)'")) 'Term popup dark mode'
            Save-TermScreenshot ('staff-'+$name+'-terms-filipino-dark')
            [void](Invoke-Js "document.querySelector('.kns-terms-modal').close()")
        }
        [void](Invoke-Js "loadPage('profile.php')")
        Wait-Js "!!document.querySelector('[data-settings-gear]')&&document.querySelector('.academic-text h2').textContent.includes('Impormasyon')" 'Profile language after navigation'
        Open-Settings 'contacts'
        [void](Invoke-Js "var f=document.querySelector('[data-settings-action=contacts]');f.elements.address.value='Browser address, Subic';f.requestSubmit()")
        Wait-Js "document.querySelector('#admin-profile-address').value==='Browser address, Subic'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Address edit saved in settings and synced to read-only card'
        Close-Settings
        Set-StaffAccount $name
        Check-Browser ([bool](Invoke-Js "document.documentElement.dataset.staffTheme==='dark'&&document.documentElement.lang==='fil'&&document.querySelector('.overview-button p').textContent==='Pangkalahatang-ideya'")) ($name+' saved portal preferences not restored after reload')
        Open-Settings 'language'
        [void](Invoke-Js "var f=document.querySelector('[data-settings-action=language]');f.elements.language.value='en';f.requestSubmit()")
        Wait-Js "document.documentElement.lang==='en'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Restore portal English'
        Close-Settings
        Check-Browser ([bool](Invoke-Js "document.querySelector('.overview-button p').textContent==='Overview'&&document.querySelector('.academic-text h2').textContent.endsWith('Information')")) 'English UI did not restore'
        Open-Settings 'appearance'
        [void](Invoke-Js "var f=document.querySelector('[data-settings-action=appearance]');f.elements.theme.value='light';f.requestSubmit()")
        Wait-Js "document.documentElement.dataset.staffTheme==='light'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Restore portal light mode'
        Close-Settings
        Check-Browser ([bool](Invoke-Js "getComputedStyle(document.querySelector('.header-main-contianer')).backgroundColor==='rgb(255, 255, 255)'")) 'Light mode did not restore original palette'
    }

    Set-StaffAccount 'admin'
    Open-Settings 'profile'
    [void](Invoke-Js "window.__settingsPosts=0;window.__originalFetch=window.fetch.bind(window);window.fetch=(url,options)=>{if(String(url).includes('staff-settings.php')&&options?.method==='POST')window.__settingsPosts++;return window.__originalFetch(url,options);};var f=document.querySelector('[data-settings-action=profile]');f.elements.full_name.value='Browser Administrator';f.requestSubmit();f.requestSubmit()")
    Wait-Js "document.querySelector('[data-settings-feedback]').textContent.includes('saved')&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Save profile name'
    Check-Browser ([bool](Invoke-Js "window.__settingsPosts===1&&document.querySelector('.student-name [data-admin-name]').textContent==='Browser Administrator'&&document.querySelector('#admin-profile-name').value==='Browser Administrator'")) 'Duplicate submit or profile synchronization failed'
    [void](Invoke-Cdp 'DOM.enable')
    $document=Invoke-Cdp 'DOM.getDocument'
    $fileNode=Invoke-Cdp 'DOM.querySelector' @{nodeId=$document.root.nodeId;selector='[data-settings-photo]'}
    [void](Invoke-Cdp 'DOM.setFileInputFiles' @{nodeId=$fileNode.nodeId;files=@($config.photo)})
    Wait-Js "document.querySelector('[data-settings-feedback]').textContent==='Your profile photo has been saved.'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Existing photo upload in settings'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-admin-photo]').src===document.querySelector('.ks-photo [data-settings-avatar]').src")) 'Photo not synced with existing card'
    Close-Settings
    Open-Settings 'security'
    [void](Invoke-Js "document.querySelector('[data-settings-show-password]').click()")
    Check-Browser ([bool](Invoke-Js "document.querySelector('[name=current_password]').type==='text'")) 'Password visibility control failed'
    [void](Invoke-Js "var f=document.querySelector('[data-settings-action=security]');f.elements.current_password.value='wrong';f.elements.new_password.value='Browser-password-fixture-123';f.elements.password_confirmation.value='Browser-password-fixture-123';f.requestSubmit()")
    Wait-Js "document.querySelector('[data-settings-feedback]').textContent.includes('incorrect')&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Wrong current password rejected in UI'
    Check-Browser ([bool](Invoke-Js "[...document.querySelectorAll('[data-settings-action=security] input')].every(x=>!x.value)")) 'Password values retained'
    Close-Settings
    Open-Settings 'appearance'
    $shell=Invoke-Js 'JSON.stringify([".sidebar-contianer",".header-main-contianer"].map(s=>{const n=document.querySelector(s),r=n.getBoundingClientRect();return [r.x,r.y,r.width,r.height];}))'
    [void](Invoke-Js "var f=document.querySelector('[data-settings-action=appearance]');f.elements.theme.value='dark';f.requestSubmit()")
    Wait-Js "document.querySelector('.admin-profile').dataset.staffTheme==='dark'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Dark preference saved'
    Save-TermScreenshot 'staff-settings-dark'
    Check-Browser ([bool](Invoke-Js "document.documentElement.dataset.staffTheme==='dark'&&getComputedStyle(document.querySelector('.header-main-contianer')).backgroundColor==='rgb(27, 41, 33)'&&getComputedStyle(document.querySelector('.main-content-contianer')).backgroundColor==='rgb(16, 25, 20)'&&getComputedStyle(document.querySelector('.sidebar-contianer')).backgroundImage.includes('11, 33, 23')")) 'Dark theme must apply to whole staff shell'
    Check-Browser ((Invoke-Js 'JSON.stringify([".sidebar-contianer",".header-main-contianer"].map(s=>{const n=document.querySelector(s),r=n.getBoundingClientRect();return [r.x,r.y,r.width,r.height];}))') -eq $shell) 'Theme changed sidebar/header geometry'
    Close-Settings
    Open-Settings 'language'
    [void](Invoke-Js "var f=document.querySelector('[data-settings-action=language]');f.elements.language.value='fil';f.requestSubmit()")
    Wait-Js "document.querySelector('#ks-panel-title').textContent==='Wika'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Filipino preference saved/applied'
    Close-Settings
    Check-Browser ([bool](Invoke-Js "document.documentElement.lang==='fil'&&document.querySelector('.menu-text').textContent==='PANGUNAHING MENU'&&document.querySelector('.overview-button p').textContent==='Pangkalahatang-ideya'&&document.querySelector('.help-contianer h1').textContent==='Kailangan ng tulong?'")) 'Filipino must apply to sidebar and header'
    Set-StaffAccount 'admin'
    Check-Browser ([bool](Invoke-Js "document.querySelector('.admin-profile').dataset.staffTheme==='dark'&&document.querySelector('.admin-profile').dataset.staffLanguage==='fil'")) 'Preferences did not persist on reload'
    Open-Settings 'language'
    [void](Invoke-Js "var f=document.querySelector('[data-settings-action=language]');f.elements.language.value='en';f.requestSubmit()")
    Wait-Js "document.querySelector('#ks-panel-title').textContent==='Language'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'English restored'
    Close-Settings
    Open-Settings 'email'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-settings-current-email]').value==='http-verified-admin@example.invalid'&&!document.querySelector('[data-settings-action=verify_email]').hidden")) 'Current email changed before verification or pending code missing'
    [void](Invoke-Js "var f=document.querySelector('[data-settings-action=verify_email]');f.elements.verification_code.value='987654';f.requestSubmit()")
    Wait-Js "document.querySelector('[data-settings-current-email]').value==='browser-verified@example.invalid'&&document.querySelector('.ks-modal').getAttribute('aria-busy')==='false'" 'Verified email saved in UI'
    Check-Browser ([bool](Invoke-Js "document.querySelector('#admin-profile-email').value==='browser-verified@example.invalid'&&document.querySelector('[data-settings-action=verify_email]').hidden")) 'Verified email not synced'
    Close-Settings
    Open-Settings 'contacts'
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$false})
    [void](Invoke-Js 'new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.ks-modal'),r=d.getBoundingClientRect();return r.width<=innerWidth-30&&r.height<=innerHeight-30&&d.scrollWidth<=d.clientWidth;})()")) 'Mobile settings popup overflows'
    Save-TermScreenshot 'staff-settings-mobile'
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Escape';code='Escape';windowsVirtualKeyCode=27})
    Wait-Js "!document.querySelector('.ks-modal').open&&document.activeElement===document.querySelector('[data-settings-gear]')" 'Escape closes popup'
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1808;height=870;deviceScaleFactor=1;mobile=$false})
    Open-Settings 'profile'
    [void](Invoke-Cdp 'Input.dispatchMouseEvent' @{type='mousePressed';x=5;y=5;button='left';clickCount=1})
    [void](Invoke-Cdp 'Input.dispatchMouseEvent' @{type='mouseReleased';x=5;y=5;button='left';clickCount=1})
    Wait-Js "!document.querySelector('.ks-modal').open" 'Outside click closes popup'
    Check-Browser ($script:browserErrors.Count -eq 0) ('JavaScript exceptions: '+($script:browserErrors -join '; '))
    Write-Output ('PASS: '+$script:checks+' staff settings browser/accessibility/layout checks. Screenshots: '+$config.artifacts)

} finally {
    if ($script:cdpSocket) { try { [void](Invoke-Cdp 'Browser.close') } catch {}; $script:cdpSocket.Dispose() }
    if ($browser -and -not $browser.HasExited) { Stop-Process -Id $browser.Id -Force -ErrorAction SilentlyContinue }
    if ($profile) {
        Get-CimInstance Win32_Process -Filter "Name='chrome.exe'" | Where-Object {
            $_.CommandLine -and $_.CommandLine.Contains('--user-data-dir=' + $profile)
        } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    }
    Start-Sleep -Milliseconds 600
}