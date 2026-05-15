<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Lightweight wrapper around the dispatch-time context array — handles
 * both array bags and Eloquent models, with dot-path lookups used by
 * placeholder rendering.
 *
 *   $ctx->get('donation.devotee.name')       // → "Hari"
 *   $ctx->get('booking.amount', '0')         // default fallback
 *
 * Models are auto-unwrapped: any dot segment that lands on an Eloquent
 * model walks `getAttribute` next, so callers don't need to flatten.
 */
final class NotificationContext
{
    public function __construct(public readonly array $data)
    {
    }

    /**
     * Build a new context with extra keys merged on top of the current
     * data. Used by NotificationService's admin_role fan-out to inject
     * a per-recipient `admin` into the context for each delivery without
     * mutating the caller's original context.
     */
    public function with(array $extras): self
    {
        return new self(array_replace($this->data, $extras));
    }

    /** Pull a value at a dot-path, default applied on miss/null. */
    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $cursor = $this->data;

        foreach ($segments as $segment) {
            if ($cursor instanceof Model) {
                $cursor = $cursor->getAttribute($segment) ?? $cursor->{$segment} ?? null;
                continue;
            }
            if (is_array($cursor)) {
                $cursor = Arr::get($cursor, $segment);
                continue;
            }
            if (is_object($cursor) && (isset($cursor->{$segment}) || method_exists($cursor, '__get'))) {
                $cursor = $cursor->{$segment} ?? null;
                continue;
            }
            $cursor = null;
            break;
        }

        return $cursor ?? $default;
    }

    /**
     * Substitute every `{{ token }}` (whitespace-tolerant) in $template
     * using either an explicit placeholder map (placeholder → dot-path)
     * or, when no map row matches, the token itself as a dot-path.
     *
     * Missing values become an empty string by default.
     */
    public function render(string $template, ?array $placeholderMap = null): string
    {
        return (string) Str::of($template)->replaceMatches(
            '/\\{\\{\\s*([a-zA-Z0-9_.]+)\\s*\\}\\}/',
            function ($m) use ($placeholderMap) {
                $token = $m[1];
                $path = $placeholderMap[$token] ?? $token;
                return self::formatForDisplay($this->get($path));
            }
        );
    }

    /**
     * Resolve a dot-path AND format the result for human display.
     *
     * Drivers (email body, WhatsApp parameters, SMS, push) all want
     * the same coercion rules:
     *
     *   • null                → empty string
     *   • Carbon / DateTime   → 'd M Y' for pure dates, 'd M Y H:i'
     *                           when there's a non-zero time. Without
     *                           this an Eloquent `date` cast renders
     *                           as "2026-05-15 00:00:00" because
     *                           Carbon's __toString is full Y-m-d H:i:s.
     *   • Stringable object   → __toString()
     *   • array / unknown obj → empty string (never inline raw bag)
     *   • scalars             → (string) cast
     *
     * This is the single coercion point — every driver calls it
     * instead of casting (string) directly so date formatting (and
     * future per-type rules) stay consistent across channels.
     */
    public function getForDisplay(string $path, string $default = ''): string
    {
        $value = $this->get($path);
        if ($value === null) {
            return $default;
        }
        return self::formatForDisplay($value);
    }

    public static function formatForDisplay(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            // Pure date cast → time is exactly midnight → show date only.
            // Anything else has a meaningful time → include it.
            $hasTime = (int) $value->format('His') !== 0;
            return $value->format($hasTime ? 'd M Y H:i' : 'd M Y');
        }

        // PHP enum casts (Eloquent's `'column' => SomeEnum::class`)
        // need special handling — they're objects with no __toString,
        // so the generic "object → empty" fallback below would silently
        // turn every enum-typed column into an empty WhatsApp param.
        // BackedEnum gets its primitive value; UnitEnum falls back to
        // the case name. Hit in donations where donation_type is cast
        // to DonationTypeEnum — see 2026-05-14 debugging session.
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        // MySQL TIME columns (eg seva_bookings.slot_time) round-trip
        // through PHP as a raw "HH:MM:SS" string with no Eloquent cast.
        // Reformat to a friendly 12-hour clock so messages show
        // "7:00 AM" instead of "07:00:00".
        if (is_string($value) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('g:i A', $timestamp);
            }
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Arrays / unknown objects — don't inline a raw representation.
        return '';
    }

    public function asArray(): array
    {
        return $this->data;
    }
}
