@echo off
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0StartPunch.ps1"
if errorlevel 1 pause
