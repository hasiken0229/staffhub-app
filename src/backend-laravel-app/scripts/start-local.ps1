$ErrorActionPreference = 'Stop'

$backendDirectory = Split-Path -Parent $PSScriptRoot
$localEnvironment = Join-Path $backendDirectory '.env.local'
$localEnvironmentExample = Join-Path $backendDirectory '.env.local.example'
$sqliteDatabase = Join-Path $backendDirectory 'database\database.sqlite'

if (-not (Test-Path -LiteralPath $localEnvironment)) {
    Copy-Item -LiteralPath $localEnvironmentExample -Destination $localEnvironment
    Write-Host '.env.local を安全なSQLite設定で作成しました。APP_KEYを生成します。'
}

if (-not (Test-Path -LiteralPath $sqliteDatabase)) {
    New-Item -ItemType File -Path $sqliteDatabase | Out-Null
}

Push-Location $backendDirectory
try {
    $environmentContents = Get-Content -LiteralPath $localEnvironment -Raw
    if ($environmentContents -match '(?m)^APP_KEY=\s*$') {
        php artisan --env=local key:generate
    }

    php artisan --env=local migrate --seed
    php artisan --env=local serve --host=127.0.0.1 --port=8000
}
finally {
    Pop-Location
}
