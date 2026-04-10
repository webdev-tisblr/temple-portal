#!/bin/bash
###############################################################################
# Hostinger Deployment Script — Shree Pataliya Hanumanji Seva Trust
#
# Usage (run ON THE SERVER via SSH):
#   First deploy:  bash deploy.sh --first-run
#   Updates:       bash deploy.sh
###############################################################################

set -e

APP_DIR="$HOME/temple-portal"
PUBLIC_HTML="$HOME/public_html"

echo "=== Temple Portal Deployment ==="
echo ""

# ─── First-time setup ────────────────────────────────────────────────────────
if [ "$1" = "--first-run" ]; then
    echo "[1/8] Cloning repository..."
    if [ ! -d "$APP_DIR" ]; then
        git clone https://github.com/webdev-tisblr/temple-portal.git "$APP_DIR"
    fi
    cd "$APP_DIR"

    echo "[2/8] Installing PHP dependencies..."
    composer install --no-dev --optimize-autoloader

    echo "[3/8] Setting up environment..."
    cp .env.production .env
    echo ""
    echo ">>> IMPORTANT: Edit .env now with real credentials!"
    echo ">>> Run: nano $APP_DIR/.env"
    echo ">>> Then re-run: bash deploy.sh --first-run"
    echo ""
    read -p "Have you edited .env with real values? (y/n) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Edit .env first, then re-run this script."
        exit 1
    fi

    echo "[4/8] Generating app key & running migrations..."
    php artisan key:generate --force
    php artisan migrate --force
    php artisan db:seed --force

    echo "[5/8] Setting up storage & permissions..."
    php artisan storage:link
    chmod -R 775 storage bootstrap/cache
    mkdir -p storage/app/receipts storage/app/exports storage/app/gallery

    echo "[6/8] Linking public_html..."
    if [ -d "$PUBLIC_HTML" ] && [ ! -L "$PUBLIC_HTML" ]; then
        rm -rf "$PUBLIC_HTML"
    fi
    ln -sf "$APP_DIR/public" "$PUBLIC_HTML"

    echo "[7/8] Building frontend (if Node.js available)..."
    if command -v node &> /dev/null; then
        npm install
        npm run build
    else
        echo ">>> Node.js not found. Build locally and push public/build/ to git."
    fi

    echo "[8/8] Caching for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    echo ""
    echo "=== First deploy complete! ==="
    echo ""
    echo "Next steps:"
    echo "  1. Set up cron job in hPanel:"
    echo "     cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
    echo "     Interval: Every 5 minutes"
    echo ""
    echo "  2. Configure Razorpay webhook:"
    echo "     URL: https://patadiyahanumanji.com/api/v1/webhooks/razorpay"
    echo "     Events: payment.captured, payment.failed, refund.created"
    echo ""
    echo "  3. Set up Cloudflare:"
    echo "     SSL: Full (strict)"
    echo "     A record: patadiyahanumanji.com -> $(hostname -I | awk '{print $1}')"
    echo ""
    exit 0
fi

# ─── Regular update deploy ───────────────────────────────────────────────────
cd "$APP_DIR"

echo "[1/5] Pulling latest code..."
git pull origin main

echo "[2/5] Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "[3/5] Running migrations..."
php artisan migrate --force

echo "[4/5] Building frontend..."
if command -v node &> /dev/null; then
    npm install
    npm run build
fi

echo "[5/5] Clearing & rebuilding cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo ""
echo "=== Deploy complete! ==="
echo "Site: https://patadiyahanumanji.com"
