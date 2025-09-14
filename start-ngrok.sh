#!/bin/bash

echo "========================================"
echo " GoHL Delyva Integration - Ngrok Setup"
echo "========================================"
echo

echo "1. Starting Laravel server..."
php artisan serve &
LARAVEL_PID=$!
sleep 3

echo "2. Starting Ngrok tunnel..."
echo
echo "IMPORTANT: Copy the HTTPS URL yang akan muncul!"
echo "Contoh: https://abc123-def456.ngrok-free.app"
echo
echo "Guna URL tu untuk setup webhook di HighLevel Marketplace:"
echo "- Order Webhook: [NGROK_URL]/webhooks/highlevel"  
echo "- Rates Callback: [NGROK_URL]/shipping/rates/callback"
echo
read -p "Press Enter to continue..."
ngrok http 8000

# Cleanup
kill $LARAVEL_PID
