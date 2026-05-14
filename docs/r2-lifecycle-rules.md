# Cloudflare R2 Bucket-Level Lifecycle Rules

**Status:** Recommended backstop. Optional but worth setting once.
**Cost:** Free. Cloudflare doesn't charge for lifecycle rules.
**Effort:** ~5 minutes total across both buckets.

---

## Why these rules exist

The Laravel scheduler at `routes/console.php` sweeps reproducible artefacts on a daily cron — see [`project-r2-lifecycle`](../) memory or `app/Console/Commands/Clean*.php` for the implementation. Those sweeps are the **primary** retention mechanism and work today.

This document configures R2's own bucket-level expiry rules as a **backstop**. They run independently of Laravel and catch every situation the cron might miss:

- Server outage longer than a day
- A bug or syntax error in a cleanup command causing it to crash silently
- Scheduled task disabled accidentally
- Migration to a new server where the cron isn't yet wired up
- An admin manually disabling the schedule for debugging and forgetting to re-enable

Without these backstop rules, any of the above scenarios silently rebuild the unbounded-growth problem we just fixed. With them, R2 enforces an outer ceiling regardless of what Laravel does.

**Important: lifecycle rules only DELETE objects.** They never write or modify. The worst case if a rule is misconfigured is "an object got deleted earlier than intended" — and since every file these rules touch is regenerable from DB, even that worst case is a brief ~1 sec regenerate cost on the next download, not data loss.

---

## Recommended rules

R2 lifecycle values **match the Laravel sweep windows exactly**. Both layers operate on the same age threshold and produce the same outcome (file deleted, regenerable on next download). No defensive multiplier is needed — there's no race condition to design around, just two redundant deletion paths that converge on the same result.

| Bucket | Prefix | R2 expiry | Why |
|---|---|---|---|
| `temple-private` | `receipts/` | 7 days | Matches `receipts:clean-generated` |
| `temple-private` | `invoices/` | 7 days | Matches `invoices:clean-generated` |
| `temple-private` | `hall-invoices/` | 7 days | Same command sweeps both invoice prefixes |
| `temple-private` | `greeting-cards/` | 1 day | Matches `greeting-cards:clean-generated` |
| `temple-public` | `daily-darshan-cards/` | 1 day | Matches `darshan:clean-share-cards` |

The CDN edge-cache (`Cache-Control: max-age=2592000` on darshan cards) is independent of R2 retention — Cloudflare's edge servers continue serving already-cached URLs even after R2 expires the origin object. So even with 1-day R2 retention, devotees who shared a WhatsApp link from yesterday keep working from CDN cache until natural edge eviction (~30 days).

**Do NOT add lifecycle rules for any other prefix in `temple-public`.** Product images, gallery photos, event banners, profile photos, donation extras — all live on `temple-public` and must be retained indefinitely. Cascade-deletion for those is handled at the application layer via `HasManagedImages` trait when the parent DB row is deleted.

---

## How to set them in Cloudflare R2

### 1. Open the bucket

1. Log into [Cloudflare dashboard](https://dash.cloudflare.com).
2. Left sidebar → **R2 Object Storage**.
3. Click the bucket name (e.g. `temple-private`).
4. Top tab bar → **Settings**.
5. Scroll to **Object lifecycle rules**.

### 2. Add the first rule (example: receipts)

1. Click **Add rule**.
2. **Rule name:** `expire-receipts-7d` (free-form; just for your reference).
3. **Apply to objects with prefix:** `receipts/`
   - ⚠️ Include the trailing slash. Without it, a future prefix like `receipts-archive/` would also match.
4. **Lifecycle action:** Select **Delete objects after N days**.
5. **Days:** `7`
6. (Optional) **Also enable: "Abort incomplete multipart uploads after N days"** with `1` day. This sweeps any partial uploads that never completed (failed PDF writes mid-stream). Free hygiene.
7. Save the rule.

### 3. Repeat for the other private-bucket prefixes

| Rule name | Prefix | Days |
|---|---|---|
| `expire-receipts-7d` | `receipts/` | 7 |
| `expire-invoices-7d` | `invoices/` | 7 |
| `expire-hall-invoices-7d` | `hall-invoices/` | 7 |
| `expire-greeting-cards-1d` | `greeting-cards/` | 1 |

You should end up with 4 rules on `temple-private`.

### 4. Switch to the public bucket

1. Bucket list → click `temple-public`.
2. Settings → Object lifecycle rules → Add rule.

| Rule name | Prefix | Days |
|---|---|---|
| `expire-daily-darshan-cards-1d` | `daily-darshan-cards/` | 1 |

**Just this one rule on the public bucket. Nothing else.**

---

## How to verify the rules are working

R2 lifecycle is asynchronous — Cloudflare processes the rules in a daily-ish background pass, not instantly. Don't expect to see deletions within the hour.

### Verify the rule is configured correctly

```bash
# From your local machine, with rclone configured for the bucket
# (or use any S3-compatible CLI with R2's S3 API).
rclone lsd r2:temple-private/ --max-depth 1
# Should show: receipts/  invoices/  hall-invoices/  greeting-cards/
```

In the R2 dashboard:
- Settings → Object lifecycle rules
- Each rule should show **Enabled** state and the correct prefix
- Click the rule to confirm the day-count and target action

### Verify a deletion actually happened (after ~24-48 hours)

Pick any file you know is older than the R2 expiry window:

```bash
# From production SSH
cd ~/domains/patadiyahanumanji.com/public_html
php artisan tinker --execute="
  // Look for any receipt path that's more than 8 days old according to DB
  \$old = App\\Models\\Receipt80G::whereNotNull('pdf_path')
    ->where('created_at', '<', now()->subDays(8))
    ->first();
  if (\$old) {
    \$path = \$old->pdf_path;
    dump('Path: '.\$path);
    dump('Still exists on R2? '.(Storage::disk('r2_private')->exists(\$path) ? 'yes' : 'NO - R2 swept it'));
  } else {
    dump('No receipts >8 days old in DB yet. Try again next week.');
  }
"
```

If a >8-day-old path no longer exists on R2 → backstop is working. (Note: Laravel cron probably already deleted it via `receipts:clean-generated` at 03:45 — that's fine. You're just confirming files do get cleaned up.)

---

## Edge cases and warnings

### Don't add a rule with an empty prefix

A rule with prefix `""` (empty) matches **every object** in the bucket. On `temple-public`, that would delete every product image, every gallery photo, every blog cover, every event banner — the entire admin-curated catalog. **Always include a specific prefix.**

### Lifecycle action types (use the right one)

R2 supports two action types per rule:

- **Delete objects after N days** ✅ — what we want
- **Abort incomplete multipart uploads after N days** ✅ — also useful, optional supplement

Do NOT use **Transition to Infrequent Access** (Cloudflare doesn't have storage tiers like S3, this option may not exist on R2 — but if it appears in the UI, ignore it).

### Versioning is unrelated

If versioning is enabled on the bucket, expiry deletes the current version but creates a delete-marker (the old version is recoverable). If you've enabled versioning intentionally for an admin audit reason, account for storage of historical versions separately. The default temple-public / temple-private buckets in this project don't have versioning enabled.

### Rules don't apply retroactively to changes

If you change a rule from 30 days to 7 days, R2 doesn't immediately re-process all objects — it just applies the new policy going forward. The next scheduled lifecycle pass will catch the older objects against the new rule. Allow ~24 hours for the new policy to fully take effect.

---

## Rolling these back

If a rule causes problems (it shouldn't — deletion of regenerable files is invisible to users — but just in case):

1. R2 dashboard → bucket → Settings → Object lifecycle rules
2. Click the problematic rule → **Disable** (preserves the rule for future re-enable) or **Delete** (removes entirely)
3. Existing scheduled-for-deletion objects stay if R2 hasn't yet processed them; already-deleted objects are gone (but regenerable on download, so no user impact)

The Laravel sweeps continue running regardless of R2 rules — disabling R2 rules just removes the backstop, doesn't break anything else.

---

## Where this fits in the bigger picture

```
Devotee clicks "Download Receipt"
        │
        ▼
  Controller checks Storage::exists()
        │
        ├─ HIT  → serve cached PDF
        │
        └─ MISS → regenerate via ReceiptService → save to R2 → serve
                                  ▲
                                  │ regen is ~1 sec, happens at most
                                  │ once per (receipt × cleanup-cycle)
                                  │
   ┌──────────────────────────────┴───────────────────────────┐
   │              How does the file get deleted?               │
   │                                                            │
   │  Primary: Laravel cron sweep                              │
   │    receipts:clean-generated --days=7                      │
   │    runs 03:45 IST daily                                   │
   │                                                            │
   │  Backstop: R2 lifecycle rule (this document)              │
   │    expire-receipts-30d                                    │
   │    runs whenever R2's background processor fires          │
   │                                                            │
   │  Either deletion path is invisible to the devotee — the   │
   │  next download regenerates from DB transparently.         │
   └────────────────────────────────────────────────────────────┘
```

See also: [`project-r2-lifecycle`](../) memory, [`routes/console.php`](../routes/console.php), [`app/Console/Commands/Clean*.php`](../app/Console/Commands/).
