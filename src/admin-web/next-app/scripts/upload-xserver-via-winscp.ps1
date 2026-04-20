param(
    [Parameter(Mandatory = $true)]
    [string]$Session,

    [string]$WinScpPath = "C:\Program Files (x86)\WinSCP\WinSCP.com",

    [string]$RemoteRoot = "/home/iwakaba/ikegami-wakaba.jp/public_html/dakoku",

    [switch]$SkipPrepare,

    [switch]$Preview
)

$ErrorActionPreference = "Stop"

function Quote-WinScpString {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Value
    )

    return '"' + ($Value -replace '"', '""') + '"'
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$prepareScript = Join-Path $PSScriptRoot "prepare-xserver-upload.ps1"
$uploadRoot = Join-Path $projectRoot "tmp\xserver-upload\dakoku"
$adminSource = Join-Path $uploadRoot "admin"
$loginSource = Join-Path $uploadRoot "login"
$remoteRootNormalized = $RemoteRoot.Trim().TrimEnd("/")
$adminRemote = "$remoteRootNormalized/admin/"
$loginRemote = "$remoteRootNormalized/login/"

if (-not (Test-Path -LiteralPath $WinScpPath)) {
    throw "WinSCP.com not found: $WinScpPath"
}

if (-not $SkipPrepare) {
    & $prepareScript
}

if (-not (Test-Path -LiteralPath $adminSource)) {
    throw "Prepared admin upload bundle not found: $adminSource"
}

if (-not (Test-Path -LiteralPath $loginSource)) {
    throw "Prepared login upload bundle not found: $loginSource"
}

$tempRoot = Join-Path $projectRoot "tmp\winscp"
New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$scriptPath = Join-Path $tempRoot "winscp-upload-$timestamp.txt"
$logPath = Join-Path $tempRoot "winscp-upload-$timestamp.log"
$previewOption = if ($Preview) { " -preview" } else { "" }

$scriptLines = @(
    "option batch abort",
    "option confirm off",
    "open $(Quote-WinScpString $Session)",
    "synchronize remote -mirror$previewOption $(Quote-WinScpString $adminSource) $(Quote-WinScpString $adminRemote)",
    "synchronize remote -mirror$previewOption $(Quote-WinScpString $loginSource) $(Quote-WinScpString $loginRemote)",
    "exit"
)

Set-Content -LiteralPath $scriptPath -Value ($scriptLines -join [Environment]::NewLine) -Encoding UTF8

Write-Host "Running WinSCP upload..."
Write-Host "  Session: $Session"
Write-Host "  Remote root: $remoteRootNormalized"
Write-Host "  Mode: $(if ($Preview) { 'preview' } else { 'upload' })"

& $WinScpPath "/script=$scriptPath" "/log=$logPath"

Write-Host ""
Write-Host "WinSCP finished."
Write-Host "  Script: $scriptPath"
Write-Host "  Log:    $logPath"
