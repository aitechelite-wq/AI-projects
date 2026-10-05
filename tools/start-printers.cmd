@echo off
setlocal
cd /d "%~dp0.."
where py >nul 2>nul
if not errorlevel 1 (
  py -3 tools\print_worker.py %*
  goto finished
)
where python >nul 2>nul
if not errorlevel 1 (
  python tools\print_worker.py %*
  goto finished
)
echo Python 3 was not found. Install Python 3 with its Windows launcher, then reopen this terminal.
:finished
echo.
pause
