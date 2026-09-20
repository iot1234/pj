@echo off
setlocal
cd /d "%~dp0.."
where php >nul 2>nul
if errorlevel 1 (echo PHP was not found in PATH. & exit /b 1)
where node >nul 2>nul
if errorlevel 1 (echo Node.js was not found in PATH. & exit /b 1)
for /f "delims=" %%I in ('php -n -r "echo PHP_BINARY;"') do set "PHP_EXE=%%I"
if not defined PHP_EXE (echo Cannot locate PHP. & exit /b 1)
for %%I in ("%PHP_EXE%") do set "EXT_DIR=%%~dpIext"
set "EXT_DIR=%EXT_DIR:\=/%"
set "TEST_INI_DIR=%CD%\storage\cache\local-test-%RANDOM%-%RANDOM%"
if exist "%TEST_INI_DIR%" (echo Temporary test directory already exists. & exit /b 1)
mkdir "%TEST_INI_DIR%" || exit /b 1
set "PHPRC=%TEST_INI_DIR%\php.ini"
(
  echo extension_dir="%EXT_DIR%"
  echo extension=pdo_mysql
  echo extension=curl
  echo extension=mbstring
  echo extension=gd
  echo extension=fileinfo
  echo extension=openssl
  echo upload_max_filesize=4M
  echo post_max_size=5M
  echo memory_limit=256M
  echo date.timezone=Asia/Bangkok
  echo display_errors=stderr
  echo log_errors=0
) > "%PHPRC%"
rem Test-only environment: never use the project's production database values.
set "APP_ENV=testing"
set "APP_DEBUG=false"
set "APP_URL=http://localhost"
set "APP_TIMEZONE=Asia/Bangkok"
set "APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
set "FORCE_HTTPS=false"
set "RUNTIME_ROLE=job"
set "TRUSTED_PROXIES="
set "SESSION_NAME=dormitory_test_session"
set "SESSION_LIFETIME_SECONDS=3600"
set "DB_HOST=127.0.0.1"
set "DB_PORT=1"
set "DB_DATABASE=testing"
set "DB_USERNAME=testing"
set "DB_PASSWORD=testing-only"
set "DB_SSL=false"
set "DB_SSL_CA="
echo === PHP unit and contract tests; no live database ===
"%PHP_EXE%" -c "%PHPRC%" tests/run.php
set "RESULT=%ERRORLEVEL%"
if not "%RESULT%"=="0" goto cleanup
echo === JavaScript interaction tests ===
node --test --test-timeout=10000 --test-reporter=spec tests/*.test.js
set "RESULT=%ERRORLEVEL%"
:cleanup
del /q "%PHPRC%"
rmdir "%TEST_INI_DIR%"
endlocal & exit /b %RESULT%
