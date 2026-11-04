# Rebuild the documentation from its Markdown source using installed Chrome or Edge.
# From the project root: powershell -NoProfile -ExecutionPolicy Bypass -File documentation/build-documentation.ps1
# Writes only the generated HTML, PDF, and first two preview PNGs in this directory.
$ErrorActionPreference = 'Stop'
$docDirectory = $PSScriptRoot
$baseName = 'KNS_Portal_CS_ELECT_1_Documentation'
$sourcePath = Join-Path $docDirectory ($baseName + '.md')
$htmlPath = Join-Path $docDirectory ($baseName + '.html')
$pdfPath = Join-Path $docDirectory ($baseName + '.pdf')
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Convert-Inline([string]$value) {
    $encoded = [System.Net.WebUtility]::HtmlEncode($value)
    $encoded = [regex]::Replace($encoded, '`([^`]+)`', '<code>$1</code>')
    return [regex]::Replace($encoded, '\*\*(.+?)\*\*', '<strong>$1</strong>')
}

function Convert-Page([string]$source) {
    $html = New-Object System.Text.StringBuilder
    $listTag = ''
    foreach ($rawLine in ($source -split '\r?\n')) {
        $line = $rawLine.Trim()
        if ($line -eq '') {
            if ($listTag) { [void]$html.AppendLine("</$listTag>"); $listTag = '' }
            continue
        }
        $item = [regex]::Match($line, '^(?:- |(\d+)\. )(.+)$')
        if ($item.Success) {
            $tag = if ($item.Groups[1].Success) { 'ol' } else { 'ul' }
            if ($listTag -ne $tag) {
                if ($listTag) { [void]$html.AppendLine("</$listTag>") }
                $listTag = $tag
                [void]$html.AppendLine("<$tag>")
            }
            [void]$html.AppendLine(('<li>' + (Convert-Inline $item.Groups[2].Value) + '</li>'))
            continue
        }
        if ($listTag) { [void]$html.AppendLine("</$listTag>"); $listTag = '' }
        $heading = [regex]::Match($line, '^(#{1,3}) (.+)$')
        if ($heading.Success) {
            $level = $heading.Groups[1].Value.Length
            [void]$html.AppendLine(("<h$level>" + (Convert-Inline $heading.Groups[2].Value) + "</h$level>"))
        } else {
            [void]$html.AppendLine(('<p>' + (Convert-Inline $line) + '</p>'))
        }
    }
    if ($listTag) { [void]$html.AppendLine("</$listTag>") }
    return $html.ToString()
}

$source = [System.IO.File]::ReadAllText($sourcePath)
$pages = [regex]::Split($source, '<!--\s*pagebreak\s*-->')
$pageCount = $pages.Count
$sections = New-Object System.Text.StringBuilder
for ($index = 0; $index -lt $pageCount; $index++) {
    $number = $index + 1
    [void]$sections.AppendLine('<section class="page" data-page="' + $number + '">')
    [void]$sections.AppendLine('<header><span>KOLEHIYO NG SUBIC</span><span>SYSTEM DOCUMENTATION</span></header>')
    [void]$sections.AppendLine('<main class="content">' + (Convert-Page $pages[$index]) + '</main>')
    [void]$sections.AppendLine('<footer><span>KNS Portal | CS ELECT 1</span><span>November 2, 2026 | ' + $number + ' / ' + $pageCount + '</span></footer></section>')
}
$template = @'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="KNS Portal student services system documentation, updated November 2, 2026.">
<title>KNS Portal: Student Services System | CS ELECT 1</title>
<style>
@page { size: Letter; margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; background: #e9eeeb; color: #18251f; font: 10.2pt/1.34 Arial, Helvetica, sans-serif; }
.page { width: 8.5in; height: 11in; padding: .58in .7in .65in; margin: 24px auto; position: relative; background: white; box-shadow: 0 2px 14px #0002; break-after: page; }
.page:last-child { break-after: auto; }
header { display: flex; justify-content: space-between; border-bottom: 1.5pt solid #226244; padding-bottom: 9px; margin-bottom: 18px; font-size: 8pt; font-weight: bold; letter-spacing: .7px; color: #226244; }
.content { height: 8.88in; }
h1 { font-size: 19pt; line-height: 1.2; color: #184d35; margin: 0 0 11px; }
h2 { font-size: 12.5pt; line-height: 1.2; color: #184d35; margin: 16px 0 7px; }
h2:first-child { margin-top: 0; }
h3 { font-size: 10.6pt; line-height: 1.3; margin: 11px 0 4px; }
p { margin: 0 0 8px; }
ul, ol { margin: 5px 0 10px; padding-left: 21px; }
li { margin: 0 0 4px; padding-left: 2px; }
code { font: 9pt/1.35 Consolas, "Courier New", monospace; overflow-wrap: anywhere; }
footer { position: absolute; bottom: .35in; left: .7in; right: .7in; border-top: 1px solid #ccd9d0; padding-top: 8px; display: flex; justify-content: space-between; font-size: 8pt; color: #516359; }
.preview-hidden { display: none; }
body.preview { zoom: 1.25; background: white; overflow: hidden; }
body.preview .page { margin: 0; box-shadow: none; }
@media print { body { background: white; } .page { margin: 0; box-shadow: none; } }
</style>
</head>
<body data-page-count="__PAGE_COUNT__" data-overflow-pages="pending">
__SECTIONS__
<script>
const pages = Array.from(document.querySelectorAll(".page"));
const overflow = pages.filter(page => {
    const content = page.querySelector(".content");
    return content.scrollHeight > content.clientHeight + 1 || content.scrollWidth > content.clientWidth + 1;
});
document.body.dataset.overflowPages = overflow.map(page => page.dataset.page).join(",");
const preview = new URLSearchParams(location.search).get("preview");
if (preview) {
    document.body.classList.add("preview");
    pages.forEach(page => page.classList.toggle("preview-hidden", page.dataset.page !== preview));
}
</script>
</body>
</html>
'@
[System.IO.File]::WriteAllText($htmlPath, $template.Replace('__PAGE_COUNT__', [string]$pageCount).Replace('__SECTIONS__', $sections.ToString()), $utf8)

$browserCandidates = @(
    (Join-Path $env:ProgramFiles 'Google/Chrome/Application/chrome.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Microsoft/Edge/Application/msedge.exe'),
    (Join-Path $env:ProgramFiles 'Microsoft/Edge/Application/msedge.exe')
)
$browserPath = $browserCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (!$browserPath) { throw 'Install Chrome or Edge to build the PDF and previews. The generated HTML can also be printed manually.' }
$browserProfile = Join-Path ([System.IO.Path]::GetTempPath()) ('kns-docs-' + [guid]::NewGuid().ToString('N'))
[void][System.IO.Directory]::CreateDirectory($browserProfile)

function Invoke-DocBrowser([string[]]$extraArguments) {
    $browserArguments = @('--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--hide-scrollbars',
        ('--user-data-dir="' + $browserProfile + '"')) + $extraArguments
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $browserPath
    $startInfo.Arguments = $browserArguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $browserProcess = New-Object System.Diagnostics.Process
    $browserProcess.StartInfo = $startInfo
    [void]$browserProcess.Start()
    $stdoutTask = $browserProcess.StandardOutput.ReadToEndAsync()
    $stderrTask = $browserProcess.StandardError.ReadToEndAsync()
    if (!$browserProcess.WaitForExit(45000)) {
        $browserProcess.Kill()
        throw 'Documentation browser timed out.'
    }
    if (!$stdoutTask.Wait(15000) -or !$stderrTask.Wait(15000)) {
        throw 'Documentation browser did not close its output streams.'
    }
    $browserOutput = $stdoutTask.Result
    $browserError = $stderrTask.Result
    $browserExitCode = $browserProcess.ExitCode
    $browserProcess.Dispose()
    if ($browserExitCode -ne 0) { throw ('Documentation browser failed: ' + $browserError) }
    return $browserOutput
}


$htmlUri = ([System.Uri]$htmlPath).AbsoluteUri
$dom = Invoke-DocBrowser @('--dump-dom', $htmlUri)
$overflowMatch = [regex]::Match($dom, 'data-overflow-pages="([^"]*)"')
if (!$overflowMatch.Success -or $overflowMatch.Groups[1].Value -ne '') {
    throw ('Page layout validation failed; overflowing pages: ' + $overflowMatch.Groups[1].Value)
}
[void](Invoke-DocBrowser @('--no-pdf-header-footer', ('--print-to-pdf="' + $pdfPath + '"'), $htmlUri))
$pdf = [System.IO.File]::ReadAllBytes($pdfPath)
$pdfText = [System.Text.Encoding]::GetEncoding(28591).GetString($pdf)
$actualPageCount = [regex]::Matches($pdfText, '/Type\s*/Page\b').Count
if (!$pdfText.StartsWith('%PDF-') -or $actualPageCount -ne $pageCount) {
    throw ("PDF validation failed: expected $pageCount pages; found $actualPageCount.")
}
for ($number = 1; $number -le [Math]::Min(2, $pageCount); $number++) {
    $previewPath = Join-Path $docDirectory ("documentation-preview-$number.png")
    [void](Invoke-DocBrowser @('--window-size=1020,1320', '--force-device-scale-factor=1',
        '--virtual-time-budget=1000', ('--screenshot="' + $previewPath + '"'), ($htmlUri + '?preview=' + $number)))
    if (!(Test-Path -LiteralPath $previewPath) -or (Get-Item -LiteralPath $previewPath).Length -lt 1000) {
        throw ("Preview $number was not created.")
    }
}
Write-Output ("Documentation built: $actualPageCount pages; no content overflow; two previews refreshed.")
Write-Output $pdfPath
