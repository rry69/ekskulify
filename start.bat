@echo off
setlocal
cd /d "%~dp0"
echo === Ekskul ^& Event - Start ===
echo [1/2] API  http://127.0.0.1:8000
echo [2/2] Frontend http://127.0.0.1:5173
echo.

where php >nul 2>&1 || (echo [ERR] php tidak ditemukan di PATH. Install PHP / Laragon dan add ke PATH. & pause & exit /b 1)
where npm >nul 2>&1 || (echo [ERR] npm tidak ditemukan. Install Node.js. & pause & exit /b 1)

REM API (PHP built-in server, router api/index.php)
start "API :8000" cmd /k "cd /d ""%~dp0"" && php -S 127.0.0.1:8000 -t api api/index.php"

REM Frontend (Vite dev, proxy /api -> :8000)
start "Frontend :5173" cmd /k "cd /d ""%~dp0frontend"" && npm run dev"

echo 2 terminal terbuka: API :8000 + Frontend :5173
echo Buka http://127.0.0.1:5173 di browser.
echo Tutup window terminal untuk stop.
pause
