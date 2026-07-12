<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Order;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\PanValidationService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

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

    public function downloadReceipt(Receipt80G $receipt): \Symfony\Component\HttpFoundation\Response|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();
        $donation = Donation::find($receipt->donation_id);

        if (!$donation || $donation->devotee_id !== $devotee->id) {
            abort(403);
        }

        // Self-heal: regenerate JUST the PDF when pdf_path is null. No R2
        // ->exists() probe — S3 HEADs from Hostinger hang, and the sweep
        // NULLs pdf_path when it deletes the object, so non-null == present.
        // Don't dispatch the Generate80GReceipt job — that path also emails
        // + WhatsApps the donor.
        if (!$receipt->pdf_path) {
            try {
                app(\App\Services\ReceiptService::class)->generateReceipt($donation);
                $receipt = $receipt->fresh();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Receipt regen failed", [
                    'donation_id' => $donation->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (!$receipt || !$receipt->pdf_path) {
                return back()->withErrors(['receipt' => 'રસીદ PDF ઉપલબ્ધ નથી. કૃપા કરી થોડી વારે ફરી પ્રયાસ કરો.']);
            }
        }

        // Redirect to a short-lived presigned R2 URL — the browser fetches
        // the PDF straight from storage instead of us proxying the bytes.
        $filename = 'receipt-' . str_replace('/', '-', $receipt->receipt_number) . '.pdf';

        return private_file_redirect($receipt->pdf_path, $filename);
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
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'date_of_birth' => 'nullable|date',
            'language' => 'nullable|in:gu,hi,en',
            'pan_number' => 'nullable|string|size:10',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $updateData = collect($validated)->except(['pan_number', 'profile_photo'])->filter()->toArray();

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile-photos', 'r2');
            $updateData['profile_photo_path'] = $path;
        }

        if (!empty($validated['pan_number'])) {
            $panService = app(PanValidationService::class);
            if (!$panService->validate($validated['pan_number'])) {
                return back()->withErrors(['pan_number' => 'અમાન્ય PAN ફોર્મેટ.']);
            }
            $updateData['pan_encrypted'] = Crypt::encryptString($validated['pan_number']);
            $updateData['pan_last_four'] = substr($validated['pan_number'], -4);
        }

        $devotee->update($updateData);

        return back()->with('success', 'પ્રોફાઇલ અપડેટ થઈ.');
    }

    public function showCompleteProfile(): View|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        // Already complete — send to dashboard
        if (! empty($devotee->name)) {
            return redirect()->route('dashboard.index');
        }

        SEOMeta::setTitle('પ્રોફાઇલ પૂર્ણ કરો');

        return view('pages.dashboard.complete-profile', compact('devotee'));
    }

    public function saveCompleteProfile(Request $request): RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'pan_number' => 'nullable|string|size:10',
        ]);

        $updateData = collect($validated)->except(['pan_number'])->filter()->toArray();

        if (! empty($validated['pan_number'])) {
            $panService = app(PanValidationService::class);
            if (! $panService->validate($validated['pan_number'])) {
                return back()->withErrors(['pan_number' => 'અમાન્ય PAN ફોર્મેટ. (ABCDE1234F)']);
            }
            $updateData['pan_encrypted'] = Crypt::encryptString($validated['pan_number']);
            $updateData['pan_last_four'] = substr($validated['pan_number'], -4);
        }

        $devotee->update($updateData);

        return redirect()->route('dashboard.index')->with('success', 'પ્રોફાઇલ સફળતાપૂર્વક બનાવવામાં આવી!');
    }
}
