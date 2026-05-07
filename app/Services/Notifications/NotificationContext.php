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
                $value = $this->get($path);
                if (is_array($value) || is_object($value)) {
                    return ''; // never inline raw structures into a message
                }
                return (string) $value;
            }
        );
    }

    public function asArray(): array
    {
        return $this->data;
    }
}
