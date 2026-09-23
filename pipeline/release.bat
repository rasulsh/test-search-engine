@echo off
rem One-command release on Windows. Same arguments as release.py, e.g.:
rem   pipeline\release.bat --csv export.csv --out release.zip
rem   pipeline\release.bat --csv export.csv --out release.zip --with-model
setlocal
set PYTHONUTF8=1
python "%~dp0release.py" %*
exit /b %ERRORLEVEL%
