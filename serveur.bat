@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title CartoFLU - Serveur local PHP

rem -----------------------------------------------------------------------------
rem  CartoFLU - Lance un serveur local PHP avec diagnostic et auto-configuration
rem  - Sert le dossier courant
rem  - Force le chargement du bon php.ini
rem  - Ajoute le dossier PHP au PATH pour les DLL/SSL
rem  - Verifie curl et openssl
rem  - Telecharge automatiquement un bundle CA si absent
rem -----------------------------------------------------------------------------

set "ROOT=%~dp0"
set "HOST=127.0.0.1"
set "PORT=8080"

rem --- Configuration utilisateur ------------------------------------------------
set "PHP_DIR=C:\php\php-8.5.4"
set "HTML_BASENAME=CartoFLU-v1.0-beta"
rem -----------------------------------------------------------------------------

set "PHP_EXE=%PHP_DIR%\php.exe"
set "PHP_INI=%PHP_DIR%\php.ini"
set "PHP_INI_DEV=%PHP_DIR%\php.ini-development"
set "PHP_INI_PROD=%PHP_DIR%\php.ini-production"
set "PHP_SSL_DIR=%PHP_DIR%\extras\ssl"
set "CACERT_PEM=%PHP_SSL_DIR%\cacert.pem"
set "CACERT_URL=https://curl.se/ca/cacert.pem"

rem Detecte automatiquement le bon fichier HTML a partir du nom de base
set "INDEX="
if exist "%ROOT%%HTML_BASENAME%.html" set "INDEX=%HTML_BASENAME%.html"
if not defined INDEX if exist "%ROOT%%HTML_BASENAME%.htm" set "INDEX=%HTML_BASENAME%.htm"
if not defined INDEX set "INDEX=%HTML_BASENAME%.html"

set "URL=http://%HOST%:%PORT%/%INDEX%"

cd /d "%ROOT%"

echo ========================================
echo   CartoFLU - Serveur local PHP
echo ========================================
echo.
echo Dossier servi : %ROOT%
echo URL           : %URL%
echo.

if not exist "%PHP_EXE%" (
    echo [ERREUR] php.exe introuvable ici :
    echo %PHP_EXE%
    echo.
    echo Verifie que le dossier PHP est correct.
    pause
    exit /b 1
)

rem S'assure qu'un php.ini existe vraiment
if not exist "%PHP_INI%" (
    if exist "%PHP_INI_DEV%" (
        echo [INFO] php.ini absent, copie de php.ini-development...
        copy /Y "%PHP_INI_DEV%" "%PHP_INI%" >nul
    ) else if exist "%PHP_INI_PROD%" (
        echo [INFO] php.ini absent, copie de php.ini-production...
        copy /Y "%PHP_INI_PROD%" "%PHP_INI%" >nul
    )
)

if not exist "%PHP_INI%" (
    echo [ERREUR] php.ini introuvable dans :
    echo %PHP_DIR%
    echo.
    echo Cree un php.ini ou copie php.ini-development / php.ini-production.
    pause
    exit /b 1
)

if not exist "%PHP_SSL_DIR%" mkdir "%PHP_SSL_DIR%" >nul 2>nul

rem Force le bon environnement PHP
set "PHPRC=%PHP_DIR%"
set "PHP_INI_SCAN_DIR="
set "PATH=%PHP_DIR%;%PATH%"

rem Telecharge automatiquement le bundle CA s'il manque
if not exist "%CACERT_PEM%" (
    echo [INFO] Bundle CA absent. Telechargement de cacert.pem...
    powershell -NoProfile -ExecutionPolicy Bypass -Command ^
      "$ProgressPreference='SilentlyContinue';" ^
      "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12;" ^
      "Invoke-WebRequest -UseBasicParsing -Uri '%CACERT_URL%' -OutFile '%CACERT_PEM%'"
    if errorlevel 1 (
        echo [ERREUR] Impossible de telecharger le bundle CA :
        echo   %CACERT_URL%
        echo.
        echo Sans ce fichier, PHP ne pourra pas verifier les certificats HTTPS distants.
        pause
        exit /b 1
    )
)

if not exist "%CACERT_PEM%" (
    echo [ERREUR] Bundle CA introuvable apres tentative de telechargement :
    echo   %CACERT_PEM%
    pause
    exit /b 1
)

rem Verifie / de-commente automatiquement les directives indispensables
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ini='%PHP_INI%';" ^
  "$cafile='%CACERT_PEM%'.Replace('\','\\');" ^
  "$raw=Get-Content -Raw -LiteralPath $ini;" ^
  "$raw=$raw -replace '(?m)^\s*;\s*extension_dir\s*=\s*"?ext"?\s*$', 'extension_dir = "ext"';" ^
  "$raw=$raw -replace '(?m)^\s*;\s*extension\s*=\s*curl\s*$', 'extension=curl';" ^
  "$raw=$raw -replace '(?m)^\s*;\s*extension\s*=\s*openssl\s*$', 'extension=openssl';" ^
  "$raw=$raw -replace '(?m)^\s*allow_url_fopen\s*=\s*Off\s*$', 'allow_url_fopen = On';" ^
  "$raw=$raw -replace '(?m)^\s*;?\s*curl\.cainfo\s*=.*$', 'curl.cainfo = "' + $cafile + '"';" ^
  "$raw=$raw -replace '(?m)^\s*;?\s*openssl\.cafile\s*=.*$', 'openssl.cafile = "' + $cafile + '"';" ^
  "if($raw -notmatch '(?m)^\s*extension_dir\s*='){ $raw += [Environment]::NewLine + 'extension_dir = "ext"' }" ^
  "if($raw -notmatch '(?m)^\s*extension\s*=\s*curl\s*$'){ $raw += [Environment]::NewLine + 'extension=curl' }" ^
  "if($raw -notmatch '(?m)^\s*extension\s*=\s*openssl\s*$'){ $raw += [Environment]::NewLine + 'extension=openssl' }" ^
  "if($raw -notmatch '(?m)^\s*allow_url_fopen\s*='){ $raw += [Environment]::NewLine + 'allow_url_fopen = On' }" ^
  "if($raw -notmatch '(?m)^\s*curl\.cainfo\s*='){ $raw += [Environment]::NewLine + 'curl.cainfo = "' + $cafile + '"' }" ^
  "if($raw -notmatch '(?m)^\s*openssl\.cafile\s*='){ $raw += [Environment]::NewLine + 'openssl.cafile = "' + $cafile + '"' }" ^
  "Set-Content -LiteralPath $ini -Value $raw -Encoding ASCII"

if errorlevel 1 (
    echo [ERREUR] Impossible de mettre a jour automatiquement php.ini.
    pause
    exit /b 1
)

echo PHP detecte : %PHP_EXE%
echo php.ini     : %PHP_INI%
echo cacert.pem  : %CACERT_PEM%
echo.
echo --- Diagnostic PHP ---------------------------------------------------------
"%PHP_EXE%" -c "%PHP_INI%" --ini
echo.
echo Modules critiques :
"%PHP_EXE%" -c "%PHP_INI%" -m | findstr /I "curl openssl"
if errorlevel 1 (
    echo.
    echo [ERREUR] curl et/ou openssl ne sont pas charges.
    echo Verifie la presence des DLL dans %PHP_DIR%\ext
    echo.
    pause
    exit /b 1
)

echo.
echo Verification des chemins CA :
"%PHP_EXE%" -c "%PHP_INI%" -r "echo 'curl.cainfo=' . ini_get('curl.cainfo') . PHP_EOL . 'openssl.cafile=' . ini_get('openssl.cafile') . PHP_EOL;"
echo ---------------------------------------------------------------------------
echo.

echo Ouverture du navigateur...
start "" "%URL%"
echo.
echo Serveur en cours d'execution.
echo Pour arreter : Ctrl+C puis O
echo.
"%PHP_EXE%" -c "%PHP_INI%" -S %HOST%:%PORT% -t "%ROOT%"

echo.
echo Serveur arrete.
pause
exit /b 0
