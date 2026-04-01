#!/bin/bash
###############################################################################
# ONE-TIME SETUP — Run this ONCE on Hostinger via SSH
# After this, all future deploys happen automatically via GitHub Actions
###############################################################################

set -e

echo "=== Temple Portal — First-time Setup ==="

# Create the directory where GitHub Actions will deploy to
mkdir -p ~/temple-portal

# Point public_html to Laravel's public folder
if [ -d ~/public_html ] && [ ! -L ~/public_html ]; then
    rm -rf ~/public_html
fi
ln -sf ~/temple-portal/public ~/public_html

echo ""
echo "=== Done! ==="
echo ""
echo "Now go to GitHub and trigger the first deploy."
echo "After deploy completes, SSH in again and run:"
echo "  cd ~/temple-portal"
echo "  cp .env.production .env"
echo "  nano .env  (fill in DB credentials)"
echo "  php artisan key:generate --force"
echo "  php artisan migrate --force"
echo "  php artisan db:seed --force"
echo "  php artisan storage:link"
echo "  chmod -R 775 storage bootstrap/cache"
echo "  php artisan config:cache"
