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
    foreach ($cookie in $config.cookies) {
        $parts = $cookie -split '=', 2
        [void](Invoke-Cdp 'Network.setCookie' @{name=$parts[0];value=$parts[1];url=$config.base;path='/'})
    }
    [void](Invoke-Cdp 'Page.navigate' @{url=($config.base + '/admin/dashboard.php?view=results')})
    Wait-Js "!!document.querySelector('[data-kns-terms][data-initialized]')" 'Students List initialization'
    Wait-Js "document.querySelector('[data-terms-banner-status]').textContent==='Active'" 'Current term banner'
    Wait-Js "[...document.querySelectorAll('link[rel=stylesheet]')].every(link=>!!link.sheet)" 'Styles loaded'
    $geometry = 'JSON.stringify([".sidebar-contianer",".header-main-contianer",".student-list-header-content",".student-list-upload",".student-import-instructions",".student-list-filters",".results-main-table"].map(selector=>{const box=document.querySelector(selector).getBoundingClientRect();return [box.x,box.y,box.width,box.height];}))'
    $before = Invoke-Js $geometry
    [void](Invoke-Js "(()=>{const b=document.querySelector('[data-terms-open]');b.focus();b.click();})()")
    Wait-Js "document.querySelector('.kns-terms-modal').open" 'Manage Terms opens'
    Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-terms-modal').matches(':modal')")) 'Background is not modal'
    Check-Browser ((Invoke-Js $geometry) -eq $before) 'Opening modal changed surrounding layout'
    Save-TermScreenshot 'academic-terms-desktop'
    Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.kns-terms-modal'),r=d.getBoundingClientRect();return r.width===640&&Math.abs(r.left+r.width/2-document.documentElement.clientWidth/2)<2&&r.height<innerHeight;})()")) 'Desktop modal dimensions/centering differ'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[name=start_date]').value===''&&document.querySelector('[name=official_end_date]').value===''")) 'Example dates pre-filled'
    Check-Browser ([bool](Invoke-Js "[...document.querySelector('[name=semester]').options].map(x=>x.value).join(',')===',1st Semester,2nd Semester,Summer'")) 'Semester options changed'
    Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.kns-terms-modal');return d.contains(document.activeElement)&&getComputedStyle(d,'::backdrop').backgroundColor!=='rgba(0, 0, 0, 0)';})()")) 'Modal focus/backdrop missing'
    Save-TermScreenshot 'academic-terms-desktop'
    [void](Invoke-Js "document.querySelector('.kns-terms-submit').focus()")
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Tab';code='Tab';windowsVirtualKeyCode=9})
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Tab';code='Tab';windowsVirtualKeyCode=9})
    Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-terms-modal').contains(document.activeElement)")) 'Keyboard focus escaped modal'
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Escape';code='Escape';windowsVirtualKeyCode=27})
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Escape';code='Escape';windowsVirtualKeyCode=27})
    Wait-Js "!document.querySelector('.kns-terms-modal').open" 'Escape closes modal'
    Check-Browser ([bool](Invoke-Js "document.activeElement===document.querySelector('[data-terms-open]')")) 'Focus did not return to Manage Terms'
    [void](Invoke-Js "(()=>{const b=document.querySelector('[data-terms-open]');b.focus();b.click();})()")
    $summer = $config.summer | ConvertTo-Json -Depth 5 -Compress
    [void](Invoke-Js ('window.__termFixture=' + $summer))
    $fill = '(()=>{const f=document.querySelector("[data-terms-form]"),v=window.__termFixture;f.elements.academic_year.value=v.academic_year;f.elements.semester.value=v.semester;const d=new Date(v.start_date+"T12:00:00Z");d.setUTCDate(d.getUTCDate()-1);f.elements.start_date.value=d.toISOString().slice(0,10);f.elements.official_end_date.value=v.official_end_date;f.dispatchEvent(new Event("input",{bubbles:true}));f.requestSubmit();})()'
    [void](Invoke-Js $fill)
    Wait-Js "document.querySelector('[data-terms-message]').dataset.state==='error'&&document.querySelector('[data-terms-message]').textContent.includes('overlap')" 'Overlap validation in modal'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[name=academic_year]').value===window.__termFixture.academic_year")) 'Validation discarded inputs'
    [void](Invoke-Js "(()=>{const f=document.querySelector('[data-terms-form]');f.elements.start_date.value=window.__termFixture.start_date;f.dispatchEvent(new Event('input',{bubbles:true}));f.requestSubmit();})()")
    Wait-Js "document.querySelector('[data-terms-message]').dataset.state==='success'&&document.querySelector('[data-terms-message]').textContent.includes('saved')" 'Schedule success'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-terms-banner-name]').textContent.includes('1st Semester')&&document.querySelector('[data-terms-banner-status]').textContent==='Active'")) 'Future save replaced current banner'
    [void](Invoke-Js "document.querySelector('[data-terms-history]').click()")
    Wait-Js "!document.querySelector('[data-terms-history-view]').hidden&&document.querySelectorAll('[data-terms-history-rows] tr[data-term-id]').length===4" 'Saved term history'
    Check-Browser ([bool](Invoke-Js "['Scheduled','Active','Completed'].every(status=>!!document.querySelector('[data-terms-history-rows] [data-status='+status+']'))")) 'History missing status'
    Save-TermScreenshot 'academic-term-history'
    [void](Invoke-Js "document.querySelector('[data-terms-history-rows] [data-status=Completed]').closest('tr').querySelector('button').click()")
    Wait-Js "document.querySelector('[data-terms-record-rows]').textContent.includes('TERM-STUDENT')" 'Enrollment history'
    [void](Invoke-Js "document.querySelector('[data-terms-kind=grades]').click()")
    Wait-Js "document.querySelector('[data-terms-record-rows]').textContent.includes('CS101')" 'Historical grades'
    [void](Invoke-Js "document.querySelector('[data-terms-kind=assessments]').click()")
    Wait-Js "document.querySelector('[data-terms-record-rows]').textContent.includes('Historical quiz')" 'Historical assessments'
    Save-TermScreenshot 'academic-term-records'
    [void](Invoke-Js "document.querySelector('[data-terms-back]').click()")
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$false})
    Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.kns-terms-modal'),r=d.getBoundingClientRect();return r.width<=innerWidth-30&&r.height<=innerHeight-30&&d.scrollWidth<=d.clientWidth;})()")) 'Mobile modal overflows'
    Save-TermScreenshot 'academic-terms-mobile'
    $mobileMetrics = Invoke-Js 'JSON.stringify({width:innerWidth,clientWidth:document.documentElement.clientWidth,grid:getComputedStyle(document.querySelector(".kns-terms-fields")).gridTemplateColumns,fields:[...document.querySelectorAll(".kns-terms-field")].map(x=>{const r=x.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width};})})'
    Check-Browser ([bool](Invoke-Js '(()=>{const fields=[...document.querySelectorAll(".kns-terms-field")].map(x=>x.getBoundingClientRect());return Math.abs(fields[0].top-fields[1].top)<2&&fields[2].top>fields[0].bottom;})()')) ('Mobile form columns incorrect: ' + $mobileMetrics)
    Save-TermScreenshot 'academic-terms-mobile'
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1808;height=870;deviceScaleFactor=1;mobile=$false})
    [void](Invoke-Js "document.querySelector('[data-terms-close]').click()")
    Check-Browser ((Invoke-Js $geometry) -eq $before) 'Surrounding UI geometry changed'
    Check-Browser ($script:browserErrors.Count -eq 0) ('JavaScript exceptions: ' + ($script:browserErrors -join '; '))
    Write-Output ('PASS: ' + $script:checks + ' browser/layout/accessibility checks. Screenshots: ' + $config.artifacts)
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