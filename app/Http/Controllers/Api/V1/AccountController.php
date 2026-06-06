<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\NotificationRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountController extends BaseApiController
{
    /**
     * Permanently delete the authenticated devotee's account.
     *
     * Required by both Apple App Store (Guideline 5.1.1(v)) and Google
     * Play (User Data policy) for any app that lets a user create an
     * account in-app.
     *
     * Behaviour: all personal data is erased immediately. Financial
     * records that Indian law requires us to retain — donations and the
     * 80G tax receipts issued against them, plus paid seva/hall/store
     * transactions — are kept but severed from any personal identifier
     * by anonymising the devotee row. The phone number is scrambled so
     * the original number is freed and a later OTP login starts a fresh,
     * empty account (i.e. from the user's perspective the account is gone).
     */
    public function destroy(Request $request): JsonResponse
    {
        $devotee = $request->user();

        DB::transaction(function () use ($devotee): void {
            // 1. Drop the profile photo from R2 (best-effort).
            if (! empty($devotee->profile_photo_path)) {
                try {
                    Storage::disk('r2')->delete($devotee->profile_photo_path);
                } catch (\Throwable $e) {
                    Log::warning('Account deletion: failed to delete profile photo', [
                        'devotee_id' => $devotee->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 2. Stop all future pushes + revoke every session token.
            $devotee->deviceTokens()->delete();
            NotificationRead::where('devotee_id', $devotee->id)->delete();
            $devotee->tokens()->delete();

            // 3. Anonymise the devotee row. Financial relations
            //    (donations, receipts, paid bookings/orders) stay linked
            //    to this now-PII-free record for legal/audit retention.
            $devotee->forceFill([
                'name' => 'Deleted devotee',
                'phone' => 'deleted_'.Str::uuid()->toString(),
                'email' => null,
                'pan_encrypted' => null,
                'pan_last_four' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'pincode' => null,
                'date_of_birth' => null,
                'profile_photo_path' => null,
                'is_active' => false,
            ])->save();
        });

        Log::info('Devotee account deleted', ['devotee_id' => $devotee->id]);

        return $this->success(null, 'Your account has been deleted.');
    }
}
