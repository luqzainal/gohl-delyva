@echo off
echo ========================================
echo  GoHL Delyva Integration - Ngrok Setup
echo ========================================
echo.

echo 1. Starting Laravel server...
start "Laravel Server" cmd /k "php artisan serve"
timeout /t 3 /nobreak >nul

echo 2. Starting Ngrok tunnel...
echo.
echo IMPORTANT: Copy the HTTPS URL yang akan muncul!
echo Contoh: https://abc123-def456.ngrok-free.app
echo.
echo Guna URL tu untuk setup webhook di HighLevel Marketplace:
echo - Order Webhook: [NGROK_URL]/webhooks/highlevel  
echo - Rates Callback: [NGROK_URL]/shipping/rates/callback
echo.
pause
ngrok http 8000
