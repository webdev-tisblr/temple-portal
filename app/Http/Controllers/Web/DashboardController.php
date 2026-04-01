<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Donation;
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
    public function index(): View
    {
        $devotee = Auth::guard('devotee')->user();

        $stats = [
            'total_donations' => Donation::where('devotee_id', $devotee->id)->sum('amount'),
            'total_bookings' => SevaBooking::where('devotee_id', $devotee->id)->count(),
            'pending_bookings' => SevaBooking::where('devotee_id', $devotee->id)->where('status', 'pending')->count(),
        ];

        $recentDonations = Donation::where('devotee_id', $devotee->id)
            ->with('receipt')->orderByDesc('created_at')->take(5)->get();

        $recentBookings = SevaBooking::where('devotee_id', $devotee->id)
            ->with('seva')->orderByDesc('created_at')->take(5)->get();

        SEOMeta::setTitle('ડેશબોર્ડ');

        return view('pages.dashboard.index', compact('devotee', 'stats', 'recentDonations', 'recentBookings'));
    }

    public function donations(): View
    {
        $devotee = Auth::guard('devotee')->user();
        $donations = Donation::where('devotee_id', $devotee->id)
            ->with('receipt')->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('મારા દાન');

        return view('pages.dashboard.donations', compact('donations'));
    }

    public function bookings(): View
    {
        $devotee = Auth::guard('devotee')->user();
        $bookings = SevaBooking::where('devotee_id', $devotee->id)
            ->with('seva')->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('મારી બુકિંગ');

        return view('pages.dashboard.bookings', compact('bookings'));
    }

    public function receipts(): View
    {
        $devotee = Auth::guard('devotee')->user();
        $donationIds = Donation::where('devotee_id', $devotee->id)->pluck('id');
        $receipts = Receipt80G::whereIn('donation_id', $donationIds)
            ->orderByDesc('created_at')->paginate(20);

        SEOMeta::setTitle('80G રસીદો');

        return view('pages.dashboard.receipts', compact('receipts'));
    }

    public function downloadReceipt(Receipt80G $receipt): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $devotee = Auth::guard('devotee')->user();
        $donation = Donation::find($receipt->donation_id);

        if (!$donation || $donation->devotee_id !== $devotee->id) {
            abort(403);
        }

        if (!$receipt->pdf_path || !Storage::disk('local')->exists($receipt->pdf_path)) {
            return back()->withErrors(['receipt' => 'રસીદ PDF ઉપલબ્ધ નથી.']);
        }

        return response()->download(
            Storage::disk('local')->path($receipt->pdf_path),
            "receipt-" . str_replace('/', '-', $receipt->receipt_number) . ".pdf"
        );
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
            $path = $request->file('profile_photo')->store('profile-photos', 'public');
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
}
