<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceToken;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-token registration endpoints for the mobile app.
 *
 * Two routes:
 *   • POST /api/v1/device-tokens       — public, no auth required.
 *     Called on app bootstrap right after the OS permission grant so
 *     anonymous installs (no OTP login yet) can still receive admin
 *     broadcasts addressed to the 'all' segment.
 *   • POST /api/v1/me/device-tokens    — auth required (legacy + login
 *     hook). Called after OTP success so the token gets associated
 *     with the devotee. Same logic — just guarantees devotee_id is set.
 *
 * Both routes use updateOrCreate on the token value, so a device that
 * registers anonymously and later logs in seamlessly upgrades from
 * devotee_id=null to devotee_id=<uuid> without orphaning anything.
 */
class DeviceTokenController extends BaseApiController
{
    /**
     * POST /api/v1/me/device-tokens (auth required)
     *
     * Used by the old app version and by the new app after login. Must
     * have an authenticated devotee — returns 401 if not.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $this->validateInput($request);

        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $row = DeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'devotee_id' => $devotee->getKey(),
                'platform' => $validated['platform'],
                ...(isset($validated['app_version']) ? ['app_version' => $validated['app_version']] : []),
                'is_active' => true,
                'last_used_at' => now(),
            ],
        );

        $this->welcomeOnce($devotee);

        return $this->success(['id' => $row->id]);
    }

    /**
     * POST /api/v1/device-tokens (auth-optional)
     *
     * Anonymous-friendly. If a Sanctum token is present we attach the
     * device to that devotee; otherwise the row is created with
     * devotee_id=null so it's still reachable via the 'all' segment.
     */
    public function registerPublic(Request $request): JsonResponse
    {
        $validated = $this->validateInput($request);

        $devoteeId = optional(auth('sanctum')->user())->getKey();

        $row = DeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                // Only overwrite devotee_id if we have one. This keeps an
                // already-logged-in devotee_id intact if the device later
                // re-registers anonymously (e.g. after a token refresh
                // before the auth state propagates).
                ...($devoteeId ? ['devotee_id' => $devoteeId] : []),
                'platform' => $validated['platform'],
                ...(isset($validated['app_version']) ? ['app_version' => $validated['app_version']] : []),
                'is_active' => true,
                'last_used_at' => now(),
            ],
        );

        if ($devoteeId !== null) {
            $this->welcomeOnce(auth('sanctum')->user());
        }

        return $this->success([
            'id' => $row->id,
            'attached_to_devotee' => (bool) $devoteeId,
        ]);
    }

    /**
     * Fire the welcome notification the first time a devotee's app tells us
     * which device to reach them on.
     *
     * WHY IT HANGS OFF TOKEN REGISTRATION, not OTP verification: the existing
     * `devotee.registered` trigger fires the instant the OTP is accepted,
     * which is BEFORE the app has posted its FCM token. A push template on
     * that trigger therefore finds no active device and is dropped — the one
     * message we most want a new devotee to see was the one that could never
     * arrive.
     *
     * `welcomed_at` is what makes it once-only. The migration that added the
     * column back-filled every existing devotee as already welcomed, so
     * deploying this does not push a welcome message at the entire userbase
     * the next time their app checks in.
     *
     * Nothing sends unless an admin has enabled a `devotee.first_login`
     * template, exactly like every other trigger.
     */
    private function welcomeOnce(?Devotee $devotee): void
    {
        if ($devotee === null || $devotee->welcomed_at !== null) {
            return;
        }

        // Stamp FIRST. A dispatch that throws must not leave the devotee
        // eligible to be welcomed again on their next app open.
        $devotee->forceFill(['welcomed_at' => now()])->save();

        app(NotificationService::class)->dispatch(
            'devotee.first_login',
            [
                'devotee' => $devotee,
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            idempotencyKey: "devotee:{$devotee->getKey()}:first-login",
        );
    }

    /**
     * DELETE /api/v1/me/device-tokens
     *
     * Body: { "token": "<the token to retire>" }
     * Used on logout — marks the row inactive instead of deleting,
     * so historical push-delivery analytics stay intact.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        DeviceToken::query()
            ->where('devotee_id', $devotee->getKey())
            ->where('token', $validated['token'])
            ->update(['is_active' => false]);

        return $this->success(['deactivated' => true]);
    }

    /**
     * @return array{token: string, platform: string, app_version?: string}
     */
    private function validateInput(Request $request): array
    {
        /** @var array{token: string, platform: string, app_version?: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32'],
            'platform' => ['required', 'in:android,ios'],
            // Optional: builds ≥ 1.4.6 report their version so pushes can
            // target capabilities (custom tone channels) per device.
            'app_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]+(\.[0-9]+)*$/'],
        ]);

        return $validated;
    }
}
