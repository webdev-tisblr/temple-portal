@props(['campaign'])

@php
    $raised = (float) $campaign->raised_amount;
    $goal   = (float) $campaign->goal_amount;
    $pct    = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
@endphp

<a href="{{ route('projects.show', $campaign->slug) }}"
   class="card-sacred block p-6 inner-glow h-full transition hover:-translate-y-0.5">

    {{-- Optional image header — keeps cards visually rich when the
         campaign has a photo; falls back to a small icon chip otherwise. --}}
    @if($campaign->cover_image_url)
        <div class="aspect-[16/9] rounded-xl overflow-hidden mb-4"
             style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
            <img src="{{ $campaign->cover_image_url }}"
                 alt="{{ $campaign->title }}"
                 class="w-full h-full object-cover">
        </div>
    @else
        <span class="flex items-center justify-center w-10 h-10 rounded-xl mb-3"
              style="background: rgba(232,117,26,0.10); color: #C45F12;">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
        </span>
    @endif

    <h3 class="text-lg font-bold leading-tight" style="color: #7A1E1E;">{{ $campaign->title }}</h3>

    @if($campaign->description)
        <p class="text-sm leading-relaxed mt-2 line-clamp-2" style="color: #5E4F3D;">
            {{ text_preview($campaign->description, 140) }}
        </p>
    @endif

    {{-- Progress --}}
    <div class="mt-4">
        <div class="flex items-baseline justify-between text-sm mb-1.5">
            <span class="font-black" style="color: #7A1E1E;">₹{{ number_format($raised) }}</span>
            <span class="text-xs" style="color: #5E4F3D;">/ ₹{{ number_format($goal) }}</span>
        </div>
        <div class="w-full h-2.5 rounded-full overflow-hidden" style="background: rgba(200,148,52,0.18);">
            <div class="h-full rounded-full transition-all duration-1000"
                 style="width: {{ $pct }}%; background: linear-gradient(90deg, #E8751A, #C89434);"></div>
        </div>
        <p class="text-[11px] mt-1.5 flex items-center justify-between" style="color: #5E4F3D;">
            <span>{{ $pct }}% {{ __('home.complete') }}</span>
            <span>{{ $campaign->donor_count ?? 0 }} {{ __('projects.donor_count') }}</span>
        </p>
    </div>

    <div class="mt-4 inline-flex items-center gap-1 text-sm font-semibold" style="color: #C45F12;">
        {{ __('home.contribute') }}
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </div>
</a>
