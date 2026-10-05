@echo off
setlocal
cd /d "%~dp0.."
set "CAFE_PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  if exist "C:\php\php.exe" (
    set "CAFE_PHP=C:\php\php.exe"
  ) else if exist "C:\xampp\php\php.exe" (
    set "CAFE_PHP=C:\xampp\php\php.exe"
  ) else (
    echo Install PHP 8.2+ to C:\php or add PHP to PATH. See README.md.
    pause
    exit /b 1
  )
)
"%CAFE_PHP%" tools\check-runtime.php
if errorlevel 1 (
  pause
  exit /b 1
)
echo Starting cafe POS. Keep this window open. Ctrl+C stops the server.
start "Cafe POS" http://127.0.0.1:8080
"%CAFE_PHP%" -d expose_php=0 -S 127.0.0.1:8080 -t public
pause
