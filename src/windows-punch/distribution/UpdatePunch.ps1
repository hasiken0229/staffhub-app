[CmdletBinding()]
param([string]$InstallPath, [switch]$NoLaunch)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

function FullPath([string]$path) { [IO.Path]::GetFullPath($path).TrimEnd('\') }
function IsWithin([string]$path, [string]$root) {
    $path.Equals($root, 'OrdinalIgnoreCase') -or $path.StartsWith($root + '\', 'OrdinalIgnoreCase')
}
function SameHash([string]$file, [string]$expected) {
    (Test-Path -LiteralPath $file -PathType Leaf) -and ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -eq $expected)
}
function ChoosePath {
    $active = @(Get-Process -Name StaffHub.PunchApp -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.SessionId -eq (Get-Process -Id $PID).SessionId })
    if ($active.Count -eq 1) {
        $candidate = Split-Path -LiteralPath $active[0].Path
        $answer = [Windows.Forms.MessageBox]::Show("打刻アプリを閉じて、この場所を更新します。打刻中の場合は、完了してから更新してください。" + [Environment]::NewLine + $candidate, '打刻端末の更新', 'OKCancel', 'Question')
        if ($answer -ne 'OK') { return $null }
        return $candidate
    }
    $pick = New-Object Windows.Forms.FolderBrowserDialog
    $pick.Description = '既存の StartPunch.cmd が入ったフォルダーを選んでください。新規設置では、空の専用フォルダーを選んでください。'
    try {
        if ($pick.ShowDialog() -eq 'OK') { return $pick.SelectedPath }
        return $null
    } finally { $pick.Dispose() }
}
function StopSelectedApp([string]$exe) {
    foreach ($p in @(Get-Process -Name StaffHub.PunchApp -ErrorAction SilentlyContinue)) {
        if (!$p.Path -or !(FullPath $p.Path).Equals($exe, 'OrdinalIgnoreCase')) { throw '別の場所の打刻アプリが起動中です。打刻アプリを終了してから更新してください。' }
        if (!$p.CloseMainWindow() -or !$p.WaitForExit(15000)) { throw 'アプリを閉じられません。手動で終了し、もう一度更新してください。' }
    }
}
function ReadManifest([string]$root) {
    $list = ConvertFrom-Json -InputObject ([IO.File]::ReadAllText((Join-Path $PSScriptRoot 'payload-manifest.json')))
    $seen = @{}
    foreach($entry in $list) {
        $rel = [string]$entry.path
        if ($rel -notmatch '^[a-zA-Z0-9_.-]+([/\\][a-zA-Z0-9_.-]+)*$' -or $rel -match '(^|[/\\])\.\.?([/\\]|$)' -or $entry.sha256 -notmatch '^[0-9a-f]{64}$' -or $seen.ContainsKey($rel)) { throw '配布ファイル一覧が不正です。ZIPを再展開してください。' }
        if ([IO.Path]::GetFileName($rel) -in @('punchsettings.json','pending-punches.json','punch-review-receipts.json')) { throw '配布物に端末固有ファイルが含まれています。' }
        if (!(SameHash (Join-Path $root $rel) $entry.sha256)) { throw "配布ファイルを検証できません。ZIPを再展開してください。 $rel" }
        $seen[$rel] = $true
    }
    foreach ($name in @('StaffHub.PunchApp.exe','StaffHub.PunchApp.dll','StaffHub.PunchApp.runtimeconfig.json','StartPunch.cmd','StartPunch.ps1','Diagnose.cmd','punchsettings.template.json')) {
        if (!$seen.ContainsKey($name)) { throw "配布物が不完全です。 $name" }
    }
    return $list
}
function CopyExtras([string]$source, [string]$stage, [hashtable]$managed) {
    $dirs = New-Object 'Collections.Generic.Queue[string]'
    $dirs.Enqueue($source)
    while ($dirs.Count) {
        $currentDir = $dirs.Dequeue()
        foreach ($item in Get-ChildItem -LiteralPath $currentDir -Force) {
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw '設置フォルダー内にリンクがあります。管理者に確認してください。' }
            $rel = $item.FullName.Substring($source.Length + 1)
            $dst = Join-Path $stage $rel
            if ($item.PSIsContainer) {
                [IO.Directory]::CreateDirectory($dst) | Out-Null
                $dirs.Enqueue($item.FullName)
            } elseif (!$managed.ContainsKey($rel)) {
                [IO.File]::Copy($item.FullName, $dst, $false)
            }
        }
    }
}

$mutex = $null
$owns = $false
$stage = $null
$previous = $null
$switched = $false
$needsLaunch = $false
$exitCode = 0
try {
    if (![Environment]::Is64BitOperatingSystem) { throw 'Windows x64 の端末で実行してください。' }
    if (!$InstallPath) { $InstallPath = ChoosePath; if (!$InstallPath) { exit 0 } }
    $target = FullPath $InstallPath
    $parent = [IO.Path]::GetDirectoryName($target)
    $payload = FullPath (Join-Path $PSScriptRoot 'Payload')
    if (!$parent -or $target.StartsWith('\\') -or !(Test-Path -LiteralPath $parent -PathType Container)) { throw 'ローカルの専用フォルダーを選んでください。' }
    $store = FullPath (Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'StaffHub\PunchApp')
    foreach($reserved in @($PSScriptRoot,$env:SystemRoot,$env:USERPROFILE,[Environment]::GetFolderPath('Desktop'),[Environment]::GetFolderPath('LocalApplicationData'),$store,$env:ProgramFiles,[Environment]::GetEnvironmentVariable('ProgramFiles(x86)'))) {
        if ($reserved -and (IsWithin (FullPath $reserved) $target)) { throw 'アプリ専用フォルダーを選んでください。' }
    }
    if ((IsWithin $target (FullPath $PSScriptRoot)) -or (IsWithin $target (FullPath $env:SystemRoot)) -or (IsWithin $target $store)) { throw 'アプリ専用フォルダーを選んでください。' }
    for ($dir = New-Object IO.DirectoryInfo($target); $null -ne $dir; $dir = $dir.Parent) {
        if ($dir.Exists -and ($dir.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'リンクを含む設置場所は選択できません。' }
    }
    $exe = Join-Path $target 'StaffHub.PunchApp.exe'
    $mutex = New-Object Threading.Mutex($false, 'Local\StaffHub.PunchApp.Launcher')
    try { $owns = $mutex.WaitOne(10000) } catch [Threading.AbandonedMutexException] { $owns = $true }
    if (!$owns) { throw '別の起動・更新処理が実行中です。しばらく待ってから実行してください。' }
    $manifest = @(ReadManifest $payload)
    $existing = Test-Path -LiteralPath $exe -PathType Leaf
    $hasTarget = Test-Path -LiteralPath $target -PathType Container
    if ($existing) {
        foreach($name in @('StaffHub.PunchApp.dll','StaffHub.PunchApp.deps.json','StaffHub.PunchApp.runtimeconfig.json','punchsettings.json')) {
            if (!(Test-Path -LiteralPath (Join-Path $target $name) -PathType Leaf)) { throw '設定済みの打刻アプリ一式があるフォルダーを選んでください。' }
        }
    } elseif ($hasTarget -and @(Get-ChildItem -LiteralPath $target -Force).Count) { throw '既存の打刻アプリか空の専用フォルダーを選んでください。' }
    $settings = Join-Path $target 'punchsettings.json'
    $managed = @{}
    $current = $existing
    foreach($e in $manifest) {
        $rel = $e.path.Replace('/','\')
        $managed[$rel] = $true
        if ($current -and !(SameHash (Join-Path $target $rel) $e.sha256)) { $current = $false }
    }
    if ($current) {
        Write-Host "最新版です。 $target"
        $needsLaunch = !$NoLaunch
    } else {
        StopSelectedApp $exe
        $stamp = [DateTime]::Now.ToString('yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0,6)
        $stage = Join-Path $parent ('.' + [IO.Path]::GetFileName($target) + '.preparing-' + $stamp)
        [IO.Directory]::CreateDirectory($stage) | Out-Null
        if ($hasTarget) { Set-Acl -LiteralPath $stage -AclObject (Get-Acl -LiteralPath $target) }
        foreach ($e in $manifest) {
            $dest = Join-Path $stage $e.path
            [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($dest)) | Out-Null
            [IO.File]::Copy((Join-Path $payload $e.path), $dest, $false)
            if (!(SameHash $dest $e.sha256)) { throw "コピーしたファイルを検証できません。 $($e.path)" }
        }
        if ($existing) {
            CopyExtras $target $stage $managed
            Set-Acl -LiteralPath (Join-Path $stage 'punchsettings.json') -AclObject (Get-Acl -LiteralPath $settings)
            if (!(SameHash (Join-Path $stage 'punchsettings.json') (Get-FileHash -LiteralPath $settings).Hash)) { throw '端末設定を引き継げませんでした。' }
        } else {
            [IO.File]::Copy((Join-Path $stage 'punchsettings.template.json'), (Join-Path $stage 'punchsettings.json'))
        }
        if ($hasTarget) {
            $backup = $target + '.before-' + $stamp
            [IO.Directory]::Move($target,$backup)
            $previous = $backup
        }
        [IO.Directory]::Move($stage,$target)
        $stage = $null
        $switched = $true
        Write-Host "更新先  $target"
        if ($previous) { Write-Host "更新前のフォルダー  $previous" }
        if ($existing) { $needsLaunch = !$NoLaunch } else { Write-Host '新規設置が完了しました。端末管理者が punchsettings.json を設定してから StartPunch.cmd を開いてください。' }
    }
} catch {
    $reason = $_.Exception.Message
    if ($previous -and !$switched -and !(Test-Path -LiteralPath $target)) {
        try { [IO.Directory]::Move($previous,$target); Write-Host '更新前のフォルダーへ復元しました。' }
        catch { Write-Host "更新前のフォルダーは $previous にあります。復元先は $target です。" }
    }
    Write-Host "更新を完了できませんでした。 $reason"
    $exitCode = 1
} finally {
    if ($stage -and $parent -and [IO.Directory]::Exists($stage) -and [IO.Path]::GetDirectoryName($stage) -eq $parent -and [IO.Path]::GetFileName($stage).StartsWith('.' + [IO.Path]::GetFileName($target) + '.preparing-')) {
        try { [IO.Directory]::Delete($stage,$true) } catch { Write-Host "作業フォルダーが残りました。 $stage" }
    }
    if ($owns) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
if ($needsLaunch) {
    & (Join-Path $target 'StartPunch.ps1')
    if ($LASTEXITCODE -ne 0) { Write-Host 'ファイルの更新は完了しました。端末設定を確認して StartPunch.cmd から起動してください。'; exit 2 }
}
exit $exitCode
