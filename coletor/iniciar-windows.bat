@echo off
title VN System - coletor de cadastros do Wi-Fi
cd /d "%~dp0"

where node >nul 2>nul
if errorlevel 1 (
  echo.
  echo  Node.js nao encontrado.
  echo  Baixe e instale em https://nodejs.org  ^(versao LTS^)
  echo  Depois rode este arquivo de novo.
  echo.
  pause
  exit /b 1
)

echo.
echo  Iniciando o coletor... deixe esta janela aberta.
echo.
start "" http://localhost:3000
node servidor.js
pause
