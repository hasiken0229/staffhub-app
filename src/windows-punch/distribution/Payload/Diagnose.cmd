@echo off
setlocal
start "" /wait "%~dp0StaffHub.PunchApp.exe" --diagnostics --output "%LOCALAPPDATA%\StaffHub\PunchApp\Diagnostics"
if errorlevel 1 (
  echo Diagnostic failed. Check the output folder and run again.
) else (
  echo Diagnostic saved. No punch, resend, heartbeat or card UID read was performed.
  echo Output: %LOCALAPPDATA%\StaffHub\PunchApp\Diagnostics
)
pause
