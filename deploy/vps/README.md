# VPS deploy bundle

Ready-to-copy server config for the Hostinger KVM 2 (Mumbai, Ubuntu 24.04)
migration. Full narrative + phase-by-phase sequence:
[`../../../VPS_MIGRATION_RUNBOOK_2026-07-12.md`](../../../VPS_MIGRATION_RUNBOOK_2026-07-12.md).

> **This branch (`vps-migration`) is NOT merged to `main`.** Merging it would
> point production deploys at the VPS. Merge only on cutover day, AFTER the
> Phase-D smoke test passes and the GitHub secrets have been repointed to the
> VPS. Until then `main` keeps deploying to shared hosting unchanged.

| File | Goes to | When |
|------|---------|------|
| `provision.sh` | run as root on the new box | Phase A |
| `php-99-temple.ini` | `/etc/php/8.3/fpm/conf.d/99-temple.ini` | Phase A (provision.sh installs it) |
| `nginx-temple-portal.conf` | `/etc/nginx/sites-available/temple-portal` | Phase B (manual — TLS cert path) |
| `supervisor-temple-worker.conf` | `/etc/supervisor/conf.d/temple-worker.conf` | Phase B (disabled until Phase H) |
| `sudoers-deploy-fpm` | `/etc/sudoers.d/deploy-fpm` | Phase A (provision.sh installs it) |
| `crontab-deploy.txt` | `crontab -e` as `deploy` | Phase B |

## deploy.yml changes on this branch (Phase E)
- rsync `remote_path` + SSH `cd`: `~/domains/.../public_html/` → `/var/www/temple-portal/`
- Removed the entire shared-hosting opcache hack (8× HTTP `_opcache_reset.php`
  + CLI invalidate) → single `sudo -n systemctl reload php8.3-fpm`.
- Kept unchanged: the `test` gate, pre-migration `backup:run`, `migrate --force`,
  idempotent seeders, `optimize:clear` + cache rebuild, the rsync retry.

## GitHub secrets (repoint values, keep the names)
`HOSTINGER_HOST` → VPS IP · `HOSTINGER_USERNAME` → `deploy` · `HOSTINGER_PORT` → `22`
· `SSH_PRIVATE_KEY` → the deploy user's key. Keeping the names means only their
**values** change in repo settings; no further workflow edits.

## .env deltas on the VPS (everything else identical)
`DB_*` → `temple`@localhost · `QUEUE_CONNECTION` stays **sync** on cutover day
(→ `redis` only in Phase H) · `CACHE_STORE`/`SESSION_DRIVER` stay `file` (→ `redis`
in Phase H) · optionally `APP_TIMEZONE=Asia/Kolkata`.

## ⚠️ Before cutover
1. **Manual DB dump kept off-machine** as an independent restore point — the
   automated `r2_backup` nightly is still unconfigured, so don't rely on it.
2. Mail: MX is on **Zoho** (independent of hosting) — decommissioning shared
   won't affect email; just re-verify outbound SMTP deliverability from the new IP.
3. Cutover on a weekday morning; **avoid Saturdays** (special darshan + peak seva).
