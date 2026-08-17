<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Order;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\PanValidationService;
use App\Services\ReceiptService;
use App\Services\SevaReceiptService;
use App\Support\SafeRedirect;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    /**
     * Every user-facing list on the dashboard is filtered to records
     * whose Payment row has captured. Pending / created / failed records
     * are intentionally hidden — they exist only as scratch state during
     * the Razorpay handoff and showing them confuses the devotee ("did
     * I donate or not?"). The mobile API enforces the same filter via
     * each /api/v1 controller; this brings the web dashboard in line.
     */
    public function index(): View
    {
        $devotee = Auth::guard('devotee')->user();

        $capturedDonations = Donation::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'));

        $capturedBookings = SevaBooking::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'));

        $stats = [
            'total_donations' => (clone $capturedDonations)->sum('amount'),
            'total_bookings' => (clone $capturedBookings)->count(),
            // 'pending_bookings' now means upcoming-confirmed-but-not-completed
            // (i.e. paid sevas whose ritual day is still ahead). The earlier
            // count silently included abandoned-cart bookings, which made
            // the metric meaningless.
            'pending_bookings' => (clone $capturedBookings)
                ->where('status', 'confirmed')
                ->where('booking_date', '>=', now()->toDateString())
                ->count(),
        ];

        $recentDonations = (clone $capturedDonations)
            ->with(['receipt', 'payment'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        $recentBookings = (clone $capturedBookings)
            ->with('seva')->orderByDesc('created_at')->take(5)->get();

        SEOMeta::setTitle('ડેશબોર્ડ');

        return view('pages.dashboard.index', compact('devotee', 'stats', 'recentDonations', 'recentBookings'));
    }

    public function donations(): View
    {
        $devotee = Auth::guard('devotee')->user();
        $donations = Donation::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with(['receipt', 'payment'])
            ->orderByDesc('created_at')
            ->paginate(20);

        SEOMeta::setTitle('મારા દાન');

        return view('pages.dashboard.donations', compact('donations'));
    }

    public function bookings(): View
    {
        $devotee = Auth::guard('devotee')->user();
        // Captured-only — was previously returning ALL bookings, so a user
        // who exited the Razorpay modal saw their abandoned booking sit
        // in the list as "પ્રતીક્ષા" (pending) forever. The mobile API
        // (SevaController::bookings) already filtered correctly; this
        // closes the parity gap.
        $bookings = SevaBooking::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('seva')->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('મારી બુકિંગ');

        return view('pages.dashboard.bookings', compact('bookings'));
    }

    public function orders(): View
    {
        $devotee = Auth::guard('devotee')->user();
        // Same captured-only filter as bookings(). The mobile API
        // (StoreController::orders) already does this; web was leaking
        // pending checkouts to the user's "My Orders" page.
        $orders = Order::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('items')->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('મારા ઓર્ડર');

        return view('pages.dashboard.orders', compact('orders'));
    }

    public function receipts(): View
    {
        $devotee = Auth::guard('devotee')->user();
        // Only collect donation ids for captured payments — a receipt should
        // never exist for an uncaptured donation, but the filter also future-
        // proofs us if any stray Receipt80G rows are seeded by tests.
        $donationIds = Donation::where('devotee_id', $devotee->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->pluck('id');
        $receipts = Receipt80G::whereIn('donation_id', $donationIds)
            ->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('80G રસીદો');

        return view('pages.dashboard.receipts', compact('receipts'));
    }

    public function downloadReceipt(Receipt80G $receipt): Response|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();
        $donation = Donation::find($receipt->donation_id);

        if (! $donation || $donation->devotee_id !== $devotee->id) {
            abort(403);
        }

        // ALLOCATION SITE #4 of 4 (item 5.4) — and the one that must NEVER
        // allocate. It is reached only through an existing Receipt80G, so
        // it uses ensurePdf(), which takes an issued receipt and can only
        // refresh its cached file. Calling generateReceipt() here would
        // have been a latent minting path if the row ever vanished.
        //
        // Self-heal: regenerate JUST the PDF when pdf_path is null. No R2
        // ->exists() probe — S3 HEADs from Hostinger hang, and the sweep
        // NULLs pdf_path when it deletes the object, so non-null == present.
        // Don't dispatch the Generate80GReceipt job — that path also emails
        // + WhatsApps the donor.
        if (! $receipt->pdf_path) {
            try {
                $receipt = app(ReceiptService::class)->ensurePdf($receipt);
            } catch (\Throwable $e) {
                Log::error('Receipt regen failed', [
                    'donation_id' => $donation->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $receipt || ! $receipt->pdf_path) {
                return back()->withErrors(['receipt' => 'રસીદ PDF ઉપલબ્ધ નથી. કૃપા કરી થોડી વારે ફરી પ્રયાસ કરો.']);
            }
        }

        // Redirect to a short-lived presigned R2 URL — the browser fetches
        // the PDF straight from storage instead of us proxying the bytes.
        $filename = 'receipt-'.str_replace('/', '-', $receipt->receipt_number).'.pdf';

        return private_file_redirect($receipt->pdf_path, $filename);
    }

    public function downloadBookingReceipt(SevaBooking $booking): Response|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        if ($booking->devotee_id !== $devotee->id) {
            abort(403);
        }

        if (! in_array($booking->status->value, ['confirmed', 'completed'], true)) {
            abort(404, 'રસીદ બુકિંગ કન્ફર્મ થયા પછી જ ઉપલબ્ધ થશે.');
        }

        // Self-heal: regenerate via the service (not the GenerateSevaReceipt
        // job — that path also notifies the devotee). No R2 ->exists()
        // probe — the sweep NULLs receipt_path, so non-null == present.
        // needsRegeneration() also catches a path rendered in a language the
        // devotee has since changed away from.
        if (app(SevaReceiptService::class)->needsRegeneration($booking)) {
            try {
                app(SevaReceiptService::class)->generateReceipt($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('On-demand seva receipt regen failed (web)', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $booking->receipt_path) {
                return back()->withErrors(['receipt' => 'રસીદ PDF ઉપલબ્ધ નથી. કૃપા કરી થોડી વારે ફરી પ્રયાસ કરો.']);
            }
        }

        $filename = 'Seva_Receipt_'.str_replace('/', '-', (string) ($booking->receipt_number ?? $booking->id)).'.pdf';

        return private_file_redirect($booking->receipt_path, $filename);
    }

    public function profile(): View
    {
        $devotee = Auth::guard('devotee')->user();
        SEOMeta::setTitle('પ્રોફાઇલ');

        return view('pages.dashboard.profile', compact('devotee'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email:rfc|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            // Optional here (this is the ongoing edit form, not signup), but
            // when given it must be a real Indian pincode — a malformed one
            // silently breaks prasad despatch.
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'date_of_birth' => 'nullable|date',
            'language' => 'nullable|in:gu,hi,en',
            'pan_number' => 'nullable|string|size:10',
            'clear_pan' => 'nullable|boolean',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'pincode.regex' => __('dashboard.pincode_invalid'),
        ]);

        $updateData = collect($validated)->except(['pan_number', 'clear_pan', 'profile_photo'])->filter()->toArray();

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile-photos', 'r2');
            $updateData['profile_photo_path'] = $path;
        }

        // PAN is OPTIONAL — but it must also be REMOVABLE (item 5.4). The
        // field submits blank whenever the devotee isn't changing it (the
        // stored value is only ever shown masked), so "blank means clear"
        // would wipe the PAN on every unrelated profile save. An explicit
        // checkbox is the only unambiguous signal. Under a strict-80G
        // regime a WRONG PAN is worse than a missing one, and until now
        // no path on web or app could clear one.
        if ($request->boolean('clear_pan')) {
            $updateData['pan_encrypted'] = null;
            $updateData['pan_last_four'] = null;
        } elseif (! empty($validated['pan_number'])) {
            $panService = app(PanValidationService::class);
            if (! $panService->validate($validated['pan_number'])) {
                return back()->withErrors(['pan_number' => __('dashboard.pan_invalid')]);
            }
            $updateData['pan_encrypted'] = Crypt::encryptString(strtoupper($validated['pan_number']));
            $updateData['pan_last_four'] = substr(strtoupper($validated['pan_number']), -4);
        }

        $devotee->update($updateData);

        // Item 5.4 → 3.1: when the donor was sent here by the checkout 80G
        // prompt, SafeRedirect carries them back to the donation they were
        // completing (with their amount/type/purpose restored) instead of
        // stranding them on the profile page. With nothing remembered this
        // is exactly the old back()-to-profile behaviour.
        return redirect()
            ->to(SafeRedirect::intended(route('dashboard.profile')))
            ->with('success', __('dashboard.profile_updated'));
    }

    public function showCompleteProfile(): View|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        // Already complete — carry on to wherever they were headed
        // (item 3.1), dashboard when there is no such place.
        if (! empty($devotee->name)) {
            return redirect()->to(\App\Support\SafeRedirect::intended(route('dashboard.index')));
        }

        SEOMeta::setTitle('પ્રોફાઇલ પૂર્ણ કરો');

        return view('pages.dashboard.complete-profile', compact('devotee'));
    }

    public function saveCompleteProfile(Request $request): RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        // First-time signup: everything except PAN and EMAIL is compulsory
        // (2026-08-17). This form is only ever reached once —
        // showCompleteProfile() bounces a devotee whose name is already set —
        // so requiring the profile here costs an existing devotee nothing
        // while giving the trust a complete record (address is what receipts
        // and prasad despatch need). PAN stays optional by law/consent (80G is
        // opt-in), and email because most devotees here have no email address
        // at all — but neither is LABELLED optional on the form, so nobody is
        // invited to skip them. A given email must still be a real one.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email:rfc|max:255',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'pan_number' => 'nullable|string|size:10',
        ], [
            'pincode.regex' => __('dashboard.pincode_invalid'),
        ]);

        $updateData = collect($validated)->except(['pan_number'])->filter()->toArray();

        if (! empty($validated['pan_number'])) {
            $panService = app(PanValidationService::class);
            if (! $panService->validate($validated['pan_number'])) {
                return back()->withErrors(['pan_number' => __('dashboard.pan_invalid_example')]);
            }
            // Store canonicalised: PanValidationService uppercases before
            // matching, so a lowercase entry validates but would otherwise
            // be encrypted verbatim and print lowercase on the receipt.
            $updateData['pan_encrypted'] = Crypt::encryptString(strtoupper($validated['pan_number']));
            $updateData['pan_last_four'] = substr(strtoupper($validated['pan_number']), -4);
        }

        $devotee->update($updateData);

        // Continue to the page that sent them here — set either by the
        // guest bounce to /login or by EnsureProfileComplete's
        // redirect()->guest() (item 3.1). Dashboard when there is none.
        return redirect()
            ->to(\App\Support\SafeRedirect::intended(route('dashboard.index')))
            ->with('success', 'પ્રોફાઇલ સફળતાપૂર્વક બનાવવામાં આવી!');
    }
}
