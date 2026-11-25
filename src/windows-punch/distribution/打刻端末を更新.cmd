@echo off
setlocal
powershell.exe -NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0UpdatePunch.ps1"
set "result=%errorlevel%"
pause
exit /b %result%
