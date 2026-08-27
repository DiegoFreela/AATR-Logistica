@echo off
REM ============================================================
REM  AATR - subir o sistema na sua maquina para testar
REM  ------------------------------------------------------------
REM  Precisa do XAMPP instalado em C:\xampp (PHP + MySQL).
REM  De dois cliques neste arquivo. Para parar, feche a janela
REM  ou aperte Ctrl+C.
REM
REM  NAO SUBA ESTE ARQUIVO PARA A HOSPEDAGEM - e so para teste.
REM ============================================================

setlocal
chcp 65001 >nul
title AATR - servidor de teste

set "XAMPP=C:\xampp"
set "PROJETO=%~dp0"
set "BANCO=aatr_local"
set "PORTA=8080"

echo.
echo  ============================================
echo   AATR - ambiente de teste
echo  ============================================
echo.

if not exist "%XAMPP%\php\php.exe" (
    echo  [ERRO] Nao achei o PHP em %XAMPP%\php\php.exe
    echo         Instale o XAMPP ou ajuste a variavel XAMPP no topo deste arquivo.
    echo.
    pause
    exit /b 1
)

REM ---------- 1. Banco de dados ----------
echo  [1/3] Subindo o MySQL...
netstat -ano | findstr ":3306 " | findstr "LISTENING" >nul
if errorlevel 1 (
    start "" /B "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
    echo        aguardando o banco responder...
    timeout /t 7 /nobreak >nul
) else (
    echo        ja estava rodando.
)

netstat -ano | findstr ":3306 " | findstr "LISTENING" >nul
if errorlevel 1 (
    echo.
    echo  [ERRO] O MySQL nao subiu. Abra o painel do XAMPP e inicie o MySQL na mao.
    echo.
    pause
    exit /b 1
)

REM ---------- 2. Criar e popular o banco ----------
echo  [2/3] Preparando o banco "%BANCO%"...
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS %BANCO% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo  [ERRO] Nao consegui criar o banco. O root do MySQL tem senha?
    pause
    exit /b 1
)

REM Importa a estrutura so na primeira vez. Para recomecar do zero,
REM apague o banco no phpMyAdmin e rode este arquivo de novo.
"%XAMPP%\mysql\bin\mysql.exe" -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%BANCO%' AND table_name='viagens';" > "%TEMP%\aatr_chk.txt"
set /p TEM_TABELA=<"%TEMP%\aatr_chk.txt"
del "%TEMP%\aatr_chk.txt" >nul 2>&1

if "%TEM_TABELA%"=="0" (
    echo        importando sql\aatr.sql ...
    "%XAMPP%\mysql\bin\mysql.exe" -u root --default-character-set=utf8mb4 %BANCO% -e "source %PROJETO%sql/aatr.sql"
    echo        estrutura criada. O acesso voce cria em /instalar.php
) else (
    echo        banco ja existe, mantendo os dados.
)

REM ---------- 3. Servidor PHP ----------
echo  [3/3] Subindo o site em http://localhost:%PORTA%
echo.
echo  ============================================
echo   PRIMEIRA VEZ - crie o seu acesso:
echo.
echo     http://localhost:%PORTA%/instalar.php
echo.
echo   Nao existe usuario nem senha de fabrica. Voce escolhe
echo   a senha na instalacao. Depois, dentro do painel,
echo   cadastre um motorista e uma viagem para testar.
echo.
echo   Site .............. http://localhost:%PORTA%/
echo   Gestor ............ http://localhost:%PORTA%/admin/login.php
echo   Motorista ......... http://localhost:%PORTA%/motorista.php
echo   Contratante ....... http://localhost:%PORTA%/rastreio.php
echo.
echo   Para parar: feche esta janela ou Ctrl+C
echo  ============================================
echo.

start "" "http://localhost:%PORTA%/instalar.php"
"%XAMPP%\php\php.exe" -S localhost:%PORTA% -t "%PROJETO%."

endlocal
