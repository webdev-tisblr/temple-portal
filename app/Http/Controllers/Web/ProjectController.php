<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DonationCampaign;
use App\Support\CampaignDonors;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * Donors shown per page — and the size of the "Top donors" list.
     * Shared with the app API so both surfaces page identically.
     */
    private const DONORS_PER_PAGE = CampaignDonors::PER_PAGE;

    public function index(): View
    {
        $projects = DonationCampaign::where('is_active', true)
            ->where('start_date', '<=', now())
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(12);

        SEOMeta::setTitle('ધામના સેવાકાર્યો — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('આપનું દરેક દાન ધામના વિકાસ અને ભક્તસેવામાં મહત્વપૂર્ણ યોગદાન આપે છે.');

        return view('pages.projects.index', compact('projects'));
    }

    public function show(string $slug): View
    {
        $project = DonationCampaign::where('slug', $slug)
            ->where('is_active', true)
            ->with('subCauses')
            ->firstOrFail();

        $project->loadCount('donations');

        SEOMeta::setTitle("{$project->title} — ધામના સેવાકાર્યો — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($project->description ?? '');

        // First page of donors (paid only), plus the Top-10-by-amount list the
        // in-page Recent/Top toggle switches to. Anonymous donations (Gupt
        // Daan) are INCLUDED in BOTH lists but the donor's name + city are
        // masked — every list goes through CampaignDonors::payload() so Top
        // can never leak a name that Recent masks. The app API reads the same
        // helper.
        $donors = CampaignDonors::recent($project->id)
            ->paginate(self::DONORS_PER_PAGE);

        $donorsJs = CampaignDonors::payload($donors->getCollection());

        $topDonorsJs = CampaignDonors::payload(
            CampaignDonors::top($project->id)
                ->limit(self::DONORS_PER_PAGE)
                ->get()
        );

        $donorsNextUrl = $donors->hasMorePages()
            ? route('projects.donors', $project->slug) . '?sort=recent&page=2'
            : null;

        // Item 5.4 — the campaign sidebar is a full second donate surface
        // posting to donate.create, so it needs the same 80G request
        // checkbox as /donate. Without it a PAN-less donor was bounced to
        // their profile with no way to say "I don't need the receipt".
        $hasPan = app(\App\Services\ReceiptService::class)
            ->devoteeHasValid80GPan(\Illuminate\Support\Facades\Auth::guard('devotee')->user());

        return view('pages.projects.show', compact('project', 'donors', 'donorsJs', 'topDonorsJs', 'donorsNextUrl', 'hasPan'));
    }

    public function donors(string $slug, Request $request): JsonResponse
    {
        $project = DonationCampaign::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        // ?sort=top → the 10 largest captured donations, no pagination.
        if ($request->query('sort') === 'top') {
            $top = CampaignDonors::top($project->id)
                ->limit(self::DONORS_PER_PAGE)
                ->get();

            return response()->json([
                'data' => CampaignDonors::payload($top),
                'next_page_url' => null,
                'total' => $top->count(),
            ]);
        }

        $donors = CampaignDonors::recent($project->id)
            ->paginate(self::DONORS_PER_PAGE)
            ->appends($request->query());

        return response()->json([
            'data' => CampaignDonors::payload($donors->getCollection()),
            'next_page_url' => $donors->nextPageUrl(),
            'total' => $donors->total(),
        ]);
    }
}
