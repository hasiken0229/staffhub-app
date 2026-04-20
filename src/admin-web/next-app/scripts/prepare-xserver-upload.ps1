$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$uploadRoot = Join-Path $projectRoot "tmp\xserver-upload\dakoku"
$adminSource = Join-Path $projectRoot "out"
$loginSource = Join-Path $projectRoot "out-login"
$adminTarget = Join-Path $uploadRoot "admin"
$loginTarget = Join-Path $uploadRoot "login"

if (-not (Test-Path $adminSource)) {
    throw "Admin build output not found: $adminSource"
}

if (-not (Test-Path $loginSource)) {
    throw "Login build output not found: $loginSource"
}

if (Test-Path $uploadRoot) {
    Remove-Item -LiteralPath $uploadRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $adminTarget -Force | Out-Null
New-Item -ItemType Directory -Path $loginTarget -Force | Out-Null

Copy-Item -Path (Join-Path $adminSource "*") -Destination $adminTarget -Recurse -Force
Copy-Item -Path (Join-Path $loginSource "*") -Destination $loginTarget -Recurse -Force

Write-Host "Prepared upload bundle:"
Write-Host "  $uploadRoot"
Write-Host ""
Write-Host "Upload these folders together to public_html/dakoku/:"
Write-Host "  admin/"
Write-Host "  login/"
