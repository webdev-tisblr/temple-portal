{{-- Adaptive donation-campaign grid — shared by the home page and the
     /projects listing so both react to how many campaigns are live:
       1 campaign  → one full-width horizontal card (image left, content right)
       2 campaigns → two cards at 50/50
       3 or more   → standard 2/3-column grid

     Pass: campaignItems (collection of DonationCampaign). --}}
@php $campaignItems = collect($campaignItems); @endphp

@if($campaignItems->count() === 1)
    @php
        $c = $campaignItems->first();
        $goal = (float) $c->goal_amount; $raised = (float) $c->raised_amount;
        $pct = $goal > 0 ? min(100, round($raised / $goal * 100)) : 0;
    @endphp
    <a href="{{ route('projects.show', $c->slug) }}"
       class="block lg:flex rounded-2xl overflow-hidden transition hover:-translate-y-0.5"
       style="background:#fff; border:1px solid #ecdfc4; box-shadow:0 2px 10px rgba(122,30,30,.06);">
        <div class="w-full aspect-[4/3] lg:w-2/5 lg:aspect-auto lg:min-h-[300px] relative bg-cover bg-no-repeat bg-center shrink-0"
             style="@if($c->cover_image_url)background-color:#fff;background-image:url('{{ $c->cover_image_url }}');@else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@endif">
            @if($c->is_featured || ($goal > 0 && $raised >= $goal))
                <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                    @if($c->is_featured)
                        <span class="px-2.5 py-1 text-xs font-bold rounded-full" style="background:#E8751A; color:#FFF7EC;">Featured</span>
                    @endif
                    @if($goal > 0 && $raised >= $goal)
                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-green-600/90 text-white">{{ __('projects.goal_reached') }}</span>
                    @endif
                </div>
            @endif
        </div>
        <div class="p-6 sm:p-8 lg:p-10 lg:w-3/5 flex flex-col justify-center">
            <div class="font-marcellus text-2xl sm:text-3xl" style="color:#7A1E1E;">{{ $c->title }}</div>
            @if($c->description)
                <div class="text-sm sm:text-[15px] mt-3 leading-relaxed line-clamp-4" style="color:#5E4F3D;">{{ text_preview($c->description, 240) }}</div>
            @endif
            <div class="mt-6 h-2.5 rounded-full" style="background:#f0e6cf;">
                <div class="h-2.5 rounded-full" style="width:{{ $pct }}%; background:linear-gradient(90deg,#E8751A,#C45F12);"></div>
            </div>
            <div class="flex justify-between mt-3 text-sm">
                <span class="font-extrabold" style="color:#7A1E1E;">₹{{ number_format($raised) }}</span>
                <span style="color:#5E4F3D;">{{ __('home.of') }} ₹{{ number_format($goal) }} · {{ $pct }}%</span>
            </div>
            <div class="mt-6">
                <span class="inline-block font-extrabold text-sm px-10 py-3 rounded-full" style="background:#7A1E1E; color:#FFF7EC;">{{ __('home.contribute') }}</span>
            </div>
        </div>
    </a>
@else
    <div class="grid sm:grid-cols-2 @if($campaignItems->count() >= 3) lg:grid-cols-3 @endif gap-6">
        @foreach($campaignItems as $c)
            @php
                $goal = (float) $c->goal_amount; $raised = (float) $c->raised_amount;
                $pct = $goal > 0 ? min(100, round($raised / $goal * 100)) : 0;
            @endphp
            <a href="{{ route('projects.show', $c->slug) }}" class="block rounded-2xl overflow-hidden transition hover:-translate-y-0.5"
               style="background:#fff; border:1px solid #ecdfc4; box-shadow:0 2px 10px rgba(122,30,30,.06);">
                <div class="w-full aspect-[4/3] relative bg-cover bg-no-repeat bg-center" style="@if($c->cover_image_url)background-color:#fff;background-image:url('{{ $c->cover_image_url }}');@else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@endif">
                    @if($goal > 0 && $raised >= $goal)
                        <span class="absolute top-3 left-3 px-2.5 py-1 text-xs font-bold rounded-full bg-green-600/90 text-white">{{ __('projects.goal_reached') }}</span>
                    @endif
                </div>
                <div class="p-6">
                    <div class="font-marcellus text-xl" style="color:#7A1E1E;">{{ $c->title }}</div>
                    @if($c->description)
                        <div class="text-sm mt-1.5 leading-relaxed line-clamp-2" style="color:#5E4F3D;">{{ text_preview($c->description, 110) }}</div>
                    @endif
                    <div class="mt-4 h-2 rounded-full" style="background:#f0e6cf;">
                        <div class="h-2 rounded-full" style="width:{{ $pct }}%; background:linear-gradient(90deg,#E8751A,#C45F12);"></div>
                    </div>
                    <div class="flex justify-between mt-2.5 text-xs">
                        <span class="font-extrabold" style="color:#7A1E1E;">₹{{ number_format($raised) }}</span>
                        <span style="color:#5E4F3D;">{{ __('home.of') }} ₹{{ number_format($goal) }} · {{ $pct }}%</span>
                    </div>
                    <div class="mt-4 text-center font-extrabold text-sm py-2.5 rounded-full" style="background:#7A1E1E; color:#FFF7EC;">{{ __('home.contribute') }}</div>
                </div>
            </a>
        @endforeach
    </div>
@endif
