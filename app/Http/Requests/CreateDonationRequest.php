<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DonationType;
use App\Models\DonationType as DonationTypeModel;
use App\Support\ExtraFieldValues;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `donation_type` is a legacy fixed enum (App\Enums\DonationType) that
     * lives in its own column, PARALLEL to the admin-managed
     * `donation_type_id`. The donate form sends the selected admin type's
     * *slug* as donation_type — which only coincidentally matches an enum
     * case for the original built-in types (general/seva/annadan/…). Any
     * admin-created type with a different slug (e.g. a birthday greeting-card
     * type, slug "birthday") would otherwise fail the `in:` rule with
     * "The selected donation type is invalid", and even if it slipped
     * through, the enum cast would throw when the model is read back.
     *
     * Coerce any slug that isn't a real enum case to `other`. The true type
     * is never lost — it's carried by donation_type_id and the donationType
     * relation, which is what the greeting-card / receipt logic keys off.
     */
    protected function prepareForValidation(): void
    {
        $this->resolveUnselectedType();

        $type = $this->input('donation_type');

        if ($type !== null && DonationType::tryFrom((string) $type) === null) {
            $this->merge(['donation_type' => DonationType::OTHER->value]);
        }
    }

    /**
     * A donation with NO type chosen is "Other", never "General" (2026-08-16).
     *
     * Neither donate surface makes the type picker mandatory, and both fall
     * back to the literal string `general` when the donor leaves it alone —
     * the app at donate_screen.dart (`?? 'general'`) and the web form at
     * pages/donation/index.blade.php (`donationType: 'general'`). Those
     * donations were being filed under General Donation, which is a REAL
     * admin category with its own name, extra fields and greeting card — so
     * "the donor didn't say" and "the donor chose General" became the same
     * row, and every report over General was inflated by the silent default.
     *
     * The published app cannot be changed, so the correction is made here,
     * on the request both surfaces share, before validation or any write:
     *
     *   • campaign gifts are left alone — they legitimately carry no type id;
     *   • a slug sent WITHOUT an id (the app's offline fallback dropdown,
     *     reachable only when GET /content/donation-types fails) is matched
     *     back to its admin type so the choice isn't lost — except `general`,
     *     which is indistinguishable from the silent default and is treated
     *     as no choice at all;
     *   • anything left over becomes `other`, linked to the admin "Other"
     *     type when one exists so reports show its real name instead of a
     *     bare legacy badge.
     *
     * Deliberately NOT a validation error: rejecting would surface a 422 to
     * donors mid-payment on a build that can never be fixed.
     */
    private function resolveUnselectedType(): void
    {
        // Campaign mode hides the type picker and sends `campaign` + a
        // campaign_id instead. Nothing to resolve, and forcing `other` here
        // would break the campaign linkage the greeting card keys off.
        if ($this->filled('campaign_id') || $this->input('donation_type') === DonationType::CAMPAIGN->value) {
            return;
        }

        // An explicit pick already carries its admin type id.
        if ($this->filled('donation_type_id')) {
            return;
        }

        $slug = trim((string) $this->input('donation_type', ''));

        if ($slug !== '' && $slug !== DonationType::GENERAL->value) {
            $match = DonationTypeModel::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if ($match !== null) {
                $this->merge(['donation_type_id' => $match->id]);

                return;
            }
        }

        $other = DonationTypeModel::query()
            ->where('slug', DonationType::OTHER->value)
            ->where('is_active', true)
            ->first();

        $this->merge([
            'donation_type' => DonationType::OTHER->value,
            // Null when no "Other" row exists (or it's been deactivated) —
            // the donation is still filed as `other`, just unlinked.
            'donation_type_id' => $other?->id,
        ]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'donation_type' => ['required', 'string', 'in:general,seva,annadan,construction,festival,campaign,other'],
            'donation_type_id' => ['nullable', 'integer', 'exists:temple_donation_types,id'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'campaign_id' => ['nullable', 'integer', 'exists:temple_donation_campaigns,id'],
            // A sub-cause, if given, must belong to the selected campaign.
            'sub_cause_id' => [
                'nullable',
                'integer',
                Rule::exists('temple_campaign_sub_causes', 'id')
                    ->where(fn ($q) => $q->where('campaign_id', $this->input('campaign_id'))),
            ],
            'anonymous' => ['nullable', 'boolean'],
            // Item 5.4 — the donor's REQUEST for a statutory 80G receipt.
            // Optional and defaulting to true so older app builds (which
            // send no such field) keep asking for one; whether they GET
            // one is decided by the PAN gate in ReceiptService, not here.
            'wants_80g' => ['nullable', 'boolean'],
        ] + ExtraFieldValues::rules();
    }
}
