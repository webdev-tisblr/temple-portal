<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationCampaign;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $projects = DonationCampaign::where('is_active', true)
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(12);

        SEOMeta::setTitle('પ્રોજેક્ટ્સ — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિરના ચાલુ પ્રોજેક્ટ્સ અને અભિયાનો. દાન કરીને સહયોગ આપો.');

        return view('pages.projects.index', compact('projects'));
    }

    public function show(string $slug): View
    {
        $project = DonationCampaign::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $project->loadCount('donations');

        SEOMeta::setTitle("{$project->title} — પ્રોજેક્ટ્સ — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($project->description ?? '');

        // First page of donors (non-anonymous, paid)
        $donors = Donation::where('campaign_id', $project->id)
            ->where('anonymous', false)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('devotee:id,name,city')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Pre-build JS-ready donor data (avoid arrow functions in Blade @json)
        $donorsJs = $donors->getCollection()->map(function ($d) {
            return [
                'name' => $d->devotee?->name ?? 'ભક્ત',
                'city' => $d->devotee?->city ?? '',
                'amount' => (float) $d->amount,
            ];
        })->values()->toArray();

        $donorsNextUrl = $donors->hasMorePages()
            ? route('projects.donors', $project->slug) . '?page=2'
            : null;

        return view('pages.projects.show', compact('project', 'donors', 'donorsJs', 'donorsNextUrl'));
    }

    public function donors(string $slug, Request $request): JsonResponse
    {
        $project = DonationCampaign::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $donors = Donation::where('campaign_id', $project->id)
            ->where('anonymous', false)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('devotee:id,name,city')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $donors->getCollection()->map(fn ($d) => [
                'name' => $d->devotee?->name ?? 'ભક્ત',
                'city' => $d->devotee?->city ?? '',
                'amount' => (float) $d->amount,
                'date' => $d->created_at->format('d/m/Y'),
            ])->values(),
            'next_page_url' => $donors->nextPageUrl(),
            'total' => $donors->total(),
        ]);
    }
}
