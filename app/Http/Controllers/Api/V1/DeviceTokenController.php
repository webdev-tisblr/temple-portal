<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-token registration endpoint for the mobile app.
 *
 * The Flutter side calls register() each time:
 *   • the user logs in
 *   • firebase_messaging emits onTokenRefresh
 * and calls deactivate() on logout, so push fan-out always targets a
 * fresh, owned set of tokens.
 */
class DeviceTokenController extends BaseApiController
{
    /**
     * POST /api/v1/me/device-tokens
     *
     * Idempotent — replays of the same token by the same devotee just
     * bump last_used_at. If the token already belongs to another
     * devotee (account swap on the same device), we re-key it to the
     * new owner so the previous owner stops receiving pushes.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32'],
            'platform' => ['required', 'in:android,ios'],
        ]);

        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $row = DeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'devotee_id' => $devotee->getKey(),
                'platform' => $validated['platform'],
                'is_active' => true,
                'last_used_at' => now(),
            ],
        );

        return $this->success(['id' => $row->id]);
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
}
