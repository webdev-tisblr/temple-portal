#!/usr/bin/env bash
# Phase A + start of Phase B — provision a fresh Hostinger KVM 2 (Ubuntu 24.04)
# for temple-portal. Run as root on the NEW box. Idempotent-ish: safe to re-run
# the apt/install steps; the swap + DB creation guard against duplication.
#
# This mirrors VPS_MIGRATION_RUNBOOK_2026-07-12.md §2-§3. Read that file for the
# full narrative and the manual steps this script deliberately leaves to you
# (nginx site, TLS cert, .env copy, DB import, smoke test).
#
# BEFORE RUNNING: set a strong DB password below (do NOT commit it back).
set -euo pipefail

DB_NAME="temple_portal"
DB_USER="temple"
DB_PASS="__SET_A_STRONG_PASSWORD__"   # <-- edit before running; keep it out of git

if [[ "$DB_PASS" == "__SET_A_STRONG_PASSWORD__" ]]; then
  echo "Edit DB_PASS at the top of this script first." >&2
  exit 1
fi

echo "== Base hardening =="
id deploy &>/dev/null || adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy/ 2>/dev/null || true
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

apt update && apt -y upgrade
apt -y install ufw fail2ban unattended-upgrades rsync curl git
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable

echo "== Swap (2G) =="
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "== Stack: PHP 8.3, nginx, MySQL 8, Redis, Supervisor =="
apt -y install nginx mysql-server redis-server supervisor certbot python3-certbot-nginx
apt -y install php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-gd php8.3-imagick \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath

command -v composer >/dev/null || {
  curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
}

echo "== PHP-FPM tuning =="
install -m 0644 "$(dirname "$0")/php-99-temple.ini" /etc/php/8.3/fpm/conf.d/99-temple.ini
systemctl reload php8.3-fpm

echo "== MySQL database + user =="
mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL ON ${DB_NAME}.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

echo "== App directory =="
mkdir -p /var/www/temple-portal && chown deploy:www-data /var/www/temple-portal

echo "== Sudoers (FPM reload for deploys) =="
install -m 0440 -o root -g root "$(dirname "$0")/sudoers-deploy-fpm" /etc/sudoers.d/deploy-fpm
visudo -c

echo "== Supervisor worker (kept DISABLED until Phase H) =="
install -m 0644 "$(dirname "$0")/supervisor-temple-worker.conf" /etc/supervisor/conf.d/temple-worker.conf
supervisorctl reread || true

cat <<'NEXT'

Provisioning done. Remaining MANUAL steps (see the runbook):
  1. nginx: cp deploy/vps/nginx-temple-portal.conf → /etc/nginx/sites-available/temple-portal,
     symlink into sites-enabled, remove default, install the Cloudflare Origin Cert, nginx -t && reload.
  2. As `deploy`: git clone the repo into /var/www/temple-portal, composer install --no-dev -o.
  3. Copy prod .env (apply the delta table) + the Firebase JSON into place.
  4. chown -R deploy:www-data storage bootstrap/cache; php artisan storage:link.
  5. Install the crontab (deploy/vps/crontab-deploy.txt) as the deploy user.
  6. Phase C: import the DB dump; compare Donation/Devotee counts vs shared.
  7. Phase D: /etc/hosts smoke test (money path!). Phase E: retarget deploy.yml (this branch).
NEXT
echo "See VPS_MIGRATION_RUNBOOK_2026-07-12.md for the full sequence."
