param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference = 'Stop'
$config = Get-Content -Raw -LiteralPath $ConfigPath | ConvertFrom-Json
$script:cdpSocket = $null
$script:cdpId = 0
$script:browserErrors = [System.Collections.Generic.List[string]]::new()
$script:checks = 0
$script:downloadEvents = [System.Collections.Generic.List[string]]::new()
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
            if ($response.method -like '*download*') { $script:downloadEvents.Add(($response | ConvertTo-Json -Depth 8 -Compress)) }
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
    $downloadDirectory=Join-Path $config.temporary 'browser-downloads'
    [void](New-Item -ItemType Directory -Path $downloadDirectory -Force)
    [void](Invoke-Cdp 'Browser.setDownloadBehavior' @{behavior='allow';downloadPath=$downloadDirectory;eventsEnabled=$true})
    [void](Invoke-Cdp 'Page.setDownloadBehavior' @{behavior='allow';downloadPath=$downloadDirectory})
    [void](Invoke-Cdp 'Page.navigate' @{url=($config.base + '/admin/dashboard.php?view=results')})
    Wait-Js "!!document.querySelector('[data-student-download][data-initialized]')" 'Download List initialized'
    Wait-Js "[...document.querySelectorAll('link[rel=stylesheet]')].every(link=>!!link.sheet)" 'Styles loaded'
    Check-Browser ([bool](Invoke-Js "(()=>{const b=document.querySelector('[data-list-open]').getBoundingClientRect(),s=document.querySelector('.searchbox').getBoundingClientRect();return b.width>0&&b.right<=s.left+1&&Math.abs(b.top-s.top)<8;})()")) 'Download List must be left of search bar'
    Save-TermScreenshot 'student-list-download-button'
    [void](Invoke-Js "document.querySelector('[data-list-open]').click()")
    Wait-Js "document.querySelector('[name=academic_year][id^=kns-list]').options.length>1&&!document.querySelector('[data-list-reset]').disabled" 'Export filters loaded'
    Check-Browser ([bool](Invoke-Js "document.querySelector('.kns-list-modal').matches(':modal')&&document.querySelector('[data-list-download]').disabled")) 'Popup or preview requirement missing'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[id=kns-list-course]').disabled&&document.querySelector('[id=kns-list-year_level]').disabled")) 'Filters not cascading'
    [void](Invoke-Js "(()=>{const f=document.querySelector('[data-list-filters]');for(const [k,v] of Object.entries({academic_year:'2026-2027',course:'BSCS',year_level:'3',section:'BSCS-3A'})){f.elements[k].value=v;f.elements[k].dispatchEvent(new Event('change',{bubbles:true}));}f.requestSubmit();})()")
    Wait-Js "document.querySelector('[data-list-count]').textContent==='27 students'&&!document.querySelector('[data-list-download]').disabled" '27-student preview'
    Check-Browser ([bool](Invoke-Js "document.querySelectorAll('[data-list-rows] tr').length===25&&document.querySelector('[data-list-page-label]').textContent==='Showing 1-25 of 27'")) 'Preview pagination/count incorrect'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-list-rows]').textContent.includes('00000000000000000123')&&document.querySelector('[data-list-rows]').textContent.includes('First Middle Student001 Jr.')")) 'Preview changed student number/full name'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-list-rows]').textContent.includes('<img src=x onerror=alert(1)>')&&!document.querySelector('[data-list-rows] img')")) 'Student name was interpreted as HTML'
    Save-TermScreenshot 'student-list-preview-desktop'
    [void](Invoke-Js "document.querySelector('[data-list-page=next]').click()")
    Check-Browser ([bool](Invoke-Js "document.querySelectorAll('[data-list-rows] tr').length===2&&document.querySelector('[data-list-rows]').textContent.includes('LIST-027')")) 'Next page omitted students'
    [void](Invoke-Js "document.querySelector('[data-list-page=previous]').click()")
    Check-Browser ([bool](Invoke-Js "document.querySelectorAll('[data-list-rows] tr').length===25")) 'Previous page failed'
    [void](Invoke-Js "(()=>{const s=document.querySelector('#kns-list-section');s.value='BSCS-3B';s.dispatchEvent(new Event('change',{bubbles:true}));})()")
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-list-download]').disabled&&document.querySelector('[data-list-preview-section]').hidden")) 'Changing filters kept stale download enabled'
    [void](Invoke-Js "document.querySelector('[data-list-filters]').requestSubmit()")
    Wait-Js "document.querySelector('[data-list-count]').textContent==='1 student'&&!document.querySelector('[data-list-download]').disabled" 'Single section preview'
    Check-Browser ([bool](Invoke-Js "document.querySelector('[data-list-rows]').textContent.includes('LIST-030')")) 'Section filter returned wrong student'
    [void](Invoke-Js "(()=>{const s=document.querySelector('#kns-list-section');s.value='BSCS-3A';s.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-list-filters]').requestSubmit();})()")
    Wait-Js "document.querySelector('[data-list-count]').textContent==='27 students'&&!document.querySelector('[data-list-download]').disabled" 'Restored group preview'
    $downloadPoint=Invoke-Js "(()=>{const r=document.querySelector('[data-list-download]').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()"
    [void](Invoke-Cdp 'Input.dispatchMouseEvent' @{type='mousePressed';x=$downloadPoint.x;y=$downloadPoint.y;button='left';clickCount=1})
    [void](Invoke-Cdp 'Input.dispatchMouseEvent' @{type='mouseReleased';x=$downloadPoint.x;y=$downloadPoint.y;button='left';clickCount=1})
    Wait-Js "document.querySelector('[data-list-message]').textContent==='Excel file ready: 27 students.'&&!document.querySelector('[data-list-download]').disabled" 'Download Excel completed'
    $file=Join-Path $downloadDirectory 'KNS_Students_2026-2027_BSCS_Year-3_BSCS-3A.xlsx'
    $deadline=[DateTime]::UtcNow.AddSeconds(15)
    while (-not (Test-Path -LiteralPath $file)) {
        [void](Invoke-Js 'true')
        if ([DateTime]::UtcNow -gt $deadline) { throw ('Browser did not save workbook. Events: '+($script:downloadEvents -join '; ')+' Files: '+((Get-ChildItem -LiteralPath $downloadDirectory | Select-Object -ExpandProperty Name) -join ', ')) }
        Start-Sleep -Milliseconds 100
    }
    Check-Browser ((Get-Item -LiteralPath $file).Length -gt 1000) 'Downloaded workbook empty'
    Copy-Item -LiteralPath $file -Destination (Join-Path $config.artifacts 'KNS_Students_2026-2027_BSCS_Year-3_BSCS-3A.xlsx') -Force
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$false})
    Check-Browser ([bool](Invoke-Js "(()=>{const d=document.querySelector('.kns-list-modal'),r=d.getBoundingClientRect();return r.width<=innerWidth-30&&r.height<=innerHeight-30&&d.scrollWidth<=d.clientWidth;})()")) 'Mobile export dialog overflows'
    [void](Invoke-Js 'new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    Save-TermScreenshot 'student-list-preview-mobile'
    $mobileMetrics=Invoke-Js "JSON.stringify({width:innerWidth,media:matchMedia('(max-width:700px)').matches,grid:getComputedStyle(document.querySelector('.kns-list-filters')).gridTemplateColumns,fields:[...document.querySelectorAll('.kns-list-field')].map(x=>{const r=x.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};})})"
    Check-Browser ([bool](Invoke-Js "(()=>{const f=[...document.querySelectorAll('.kns-list-field')].map(x=>x.getBoundingClientRect());return f.every(r=>r.width>=100)&&Math.abs(f[0].top-f[1].top)<2&&f[2].top>f[0].bottom;})()")) ('Mobile filter layout incorrect: '+$mobileMetrics)
    [void](Invoke-Js "document.querySelector('[data-list-reset]').click()")
    Check-Browser ([bool](Invoke-Js "[...document.querySelectorAll('[data-list-filters] select')].every(s=>!s.value)&&document.querySelector('[data-list-download]').disabled&&document.querySelector('[data-list-preview-section]').hidden")) 'Reset Filters retained stale preview'
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Escape';code='Escape';windowsVirtualKeyCode=27})
    [void](Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Escape';code='Escape';windowsVirtualKeyCode=27})
    Wait-Js "!document.querySelector('.kns-list-modal').open" 'Escape closes export popup'
    Check-Browser ([bool](Invoke-Js "document.activeElement===document.querySelector('[data-list-open]')")) 'Focus did not return to Download List'
    [void](Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1808;height=870;deviceScaleFactor=1;mobile=$false})
    [void](Invoke-Cdp 'Network.clearBrowserCookies')
    foreach ($cookie in $config.registrar_cookies) {
        $parts=$cookie -split '=',2
        [void](Invoke-Cdp 'Network.setCookie' @{name=$parts[0];value=$parts[1];url=$config.base;path='/'})
    }
    [void](Invoke-Cdp 'Page.navigate' @{url=($config.base + '/admin/dashboard.php?view=results')})
    Wait-Js "!!document.querySelector('[data-student-download][data-initialized]')" 'Registrar export controls'
    [void](Invoke-Js "document.querySelector('[data-list-open]').click()")
    Wait-Js "document.querySelector('#kns-list-academic_year').options.length>1&&!document.querySelector('[data-list-reset]').disabled" 'Registrar options'
    [void](Invoke-Js "(()=>{const f=document.querySelector('[data-list-filters]');for(const [k,v] of Object.entries({academic_year:'2026-2027',course:'BSCS',year_level:'3',section:'BSCS-3A'})){f.elements[k].value=v;f.elements[k].dispatchEvent(new Event('change',{bubbles:true}));}f.requestSubmit();})()")
    Wait-Js "document.querySelector('[data-list-count]').textContent==='27 students'&&!document.querySelector('[data-list-download]').disabled" 'Registrar preview/export available'
    Check-Browser ($script:browserErrors.Count -eq 0) ('JavaScript exceptions: '+($script:browserErrors -join '; '))
    Write-Output ('PASS: '+$script:checks+' export browser/layout/accessibility checks. Artifacts: '+$config.artifacts)
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