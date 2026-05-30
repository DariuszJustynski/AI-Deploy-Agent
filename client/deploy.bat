@echo off
:: ============================================================
:: AI Deploy Agent v2 — Windows Batch Launcher
:: ============================================================
:: Usage:
::   deploy.bat /deploy
::   deploy.bat /deploy:upload
::   deploy.bat /deploy:install
::   deploy.bat /rollback
::   deploy.bat /status
::   deploy.bat /logs
::   deploy.bat /logs 100
::   deploy.bat /set-source "C:\projects\zakinfo\dist"
::   deploy.bat /set-package "2026-05-27_21-00-00_site.zip"
::   deploy.bat /set-target "zakinfo"
::   deploy.bat /clean-root:dry-run
::   deploy.bat /clean-root
:: ============================================================

setlocal
cd /d "%~dp0"

if "%1"=="" (
  node command-router.js
) else (
  node command-router.js %*
)

endlocal
