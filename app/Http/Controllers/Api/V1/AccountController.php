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
            //    The scrambled phone must fit varchar(15) and stay unique:
            //    'del_' + 11 random chars fills it exactly and can never
            //    collide with a real (all-digit) number.
            $devotee->forceFill([
                'name' => 'Deleted devotee',
                'phone' => 'del_'.Str::lower(Str::random(11)),
                'email' => null,
                'pan_encrypted' => null,
                'pan_last_four' => null,
                'address' => null,
                'city' => null,
                // NOT NULL column — blank it instead of nulling.
                'state' => '',
                'pincode' => null,
                'date_of_birth' => null,
                'profile_photo_path' => null,
                'is_active' => false,
            ])->save();

            // 4. Scrub the PII copies denormalised onto retained financial
            //    records, per the privacy policy ("anonymised so they are
            //    no longer linked to your personal identity").
            //    Donations: flip the anonymous flag so every public donor
            //    list renders "રામ ભરોસે" through the existing logic.
            DB::table('temple_donations')
                ->where('devotee_id', $devotee->id)
                ->update(['anonymous' => true]);

            DB::table('temple_seva_bookings')
                ->where('devotee_id', $devotee->id)
                ->update(['devotee_name_for_seva' => null]);

            DB::table('temple_hall_bookings')
                ->where('devotee_id', $devotee->id)
                ->update([
                    'contact_name' => 'Deleted devotee',
                    'contact_phone' => 'deleted',
                ]);

            DB::table('temple_orders')
                ->where('devotee_id', $devotee->id)
                ->update([
                    'shipping_name' => 'Deleted devotee',
                    'shipping_phone' => 'deleted',
                    'shipping_address' => 'deleted',
                ]);

            // Notification audit logs: drop the plaintext/masked recipient
            //    (phone/email); the one-way hash stays for idempotency.
            DB::table('temple_notification_logs')
                ->where('devotee_id', $devotee->id)
                ->update([
                    'recipient_value' => null,
                    'recipient_masked' => null,
                ]);
        });

        Log::info('Devotee account deleted', ['devotee_id' => $devotee->id]);

        return $this->success(null, 'Your account has been deleted.');
    }
}
