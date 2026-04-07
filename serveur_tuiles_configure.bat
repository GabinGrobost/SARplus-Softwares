@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title CartoFLU - Serveur local PHP

rem -----------------------------------------------------------------------------
rem  CartoFLU - Lance un serveur local compatible PHP
rem  - Sert le dossier courant
rem  - Ouvre l'application dans le navigateur
rem  - PHP configure manuellement
rem -----------------------------------------------------------------------------

set "ROOT=%~dp0"
set "HOST=127.0.0.1"
set "PORT=8080"

rem --- Configuration utilisateur ------------------------------------------------
set "PHP_DIR=C:\php\php-8.5.4"
set "HTML_BASENAME=CartoFLU-v1.0-beta"
rem -----------------------------------------------------------------------------

set "PHP_EXE=%PHP_DIR%\php.exe"

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

echo PHP detecte : %PHP_EXE%
echo.
echo Ouverture du navigateur...
start "" "%URL%"
echo.
echo Serveur en cours d'execution.
echo Pour arreter : Ctrl+C puis O
echo.
"%PHP_EXE%" -S %HOST%:%PORT% -t "%ROOT%"

echo.
echo Serveur arrete.
pause
exit /b 0
