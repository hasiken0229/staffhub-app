$ErrorActionPreference = 'Stop'
$launcherMutex = $null
$ownsLauncherMutex = $false
try {
    $launcherMutex = New-Object System.Threading.Mutex($false, 'Local\StaffHub.PunchApp.Launcher')
    try { $ownsLauncherMutex = $launcherMutex.WaitOne(10000) }
    catch [System.Threading.AbandonedMutexException] { $ownsLauncherMutex = $true }
    if (-not $ownsLauncherMutex) { throw 'Another launch is in progress. Wait a moment and try again.' }
    $sessionId = [System.Diagnostics.Process]::GetCurrentProcess().SessionId
    $existingApp = Get-Process -Name 'StaffHub.PunchApp' -ErrorAction SilentlyContinue | Where-Object { $_.SessionId -eq $sessionId } | Select-Object -First 1
    if ($existingApp) {
        Write-Host 'The punch app is already running. Use its existing window.'
        exit 0
    }
    $settingsPath = Join-Path $PSScriptRoot 'punchsettings.json'
    if (-not (Test-Path -LiteralPath $settingsPath -PathType Leaf)) {
        throw 'punchsettings.json is missing. Copy punchsettings.template.json and enter the device code and secret. See the setup guide.'
    }
    $settings = Get-Content -LiteralPath $settingsPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $baseUri = $null
    $validUrl = [Uri]::TryCreate([string]$settings.apiBaseUrl, [UriKind]::Absolute, [ref]$baseUri)
    if (-not $validUrl -or $baseUri.Scheme -ne 'https' -or $baseUri.UserInfo -or $baseUri.Query -or $baseUri.Fragment) {
        throw 'The API URL must be a valid HTTPS URL. Check punchsettings.json.'
    }
    if ([string]::IsNullOrWhiteSpace([string]$settings.deviceCode) -or [string]::IsNullOrWhiteSpace([string]$settings.deviceSecret) -or [string]$settings.deviceCode -match 'CHANGE_ME' -or [string]$settings.deviceSecret -match 'CHANGE_ME') {
        throw 'The device code or secret is not configured. Check punchsettings.json.'
    }
    if ([string]$settings.readerMode -ne 'RC_S380') {
        throw 'Set readerMode to RC_S380 for the card reader. Check punchsettings.json.'
    }
    $appPath = Join-Path $PSScriptRoot 'StaffHub.PunchApp.exe'
    if (-not (Test-Path -LiteralPath $appPath -PathType Leaf)) {
        throw 'StaffHub.PunchApp.exe is missing. Extract the entire ZIP again.'
    }
    Start-Process -FilePath $appPath -WorkingDirectory $PSScriptRoot
    exit 0
}
catch {
    Write-Host ('Cannot start: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
finally {
    if ($ownsLauncherMutex -and $null -ne $launcherMutex) { $launcherMutex.ReleaseMutex() }
    if ($null -ne $launcherMutex) { $launcherMutex.Dispose() }
}
