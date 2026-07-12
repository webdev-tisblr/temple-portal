{{-- Sticky darshan widget (design import). Expects $todayTiming (nullable)
     and $schedule (array from DarshanTiming::scheduleNow()). --}}
@if(isset($todayTiming) && $todayTiming)
    @php
        $isOpenNow = $schedule['is_open'] ?? false;
        $dotColor = $isOpenNow ? '#26b24a' : '#e0b47b';
        $statusTxt = $isOpenNow ? __('home.temple_open') : __('home.temple_closed');
        $fmt = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('h:i A') : null;
    @endphp
    <div x-data="{ open: false }" x-cloak
         class="hidden lg:flex fixed right-0 top-44 z-40 items-start">
        <button type="button" @click="open = !open"
                class="rounded-l-xl px-3 py-4 flex flex-col items-center gap-2 shadow-lg"
                style="background:#7A1E1E; color:#F3DCAE;">
            <span class="w-2.5 h-2.5 rounded-full" style="background:{{ $dotColor }};"></span>
            <span class="text-xs font-extrabold tracking-widest" style="writing-mode:vertical-rl;">{{ __('nav.darshan') }}</span>
        </button>
        <div x-show="open" x-transition
             class="w-72 rounded-l-2xl p-6 shadow-2xl" style="background:#FBF5EA;">
            <div class="flex items-center justify-between">
                <span class="font-marcellus text-base" style="color:#7A1E1E;">{{ __('darshan.todays') }}</span>
                <span class="flex items-center gap-1.5 text-[11px] font-extrabold" style="color:{{ $isOpenNow ? '#1f5a2a' : '#8a5a1a' }};">
                    <span class="w-1.5 h-1.5 rounded-full" style="background:{{ $dotColor }};"></span>{{ $statusTxt }}
                </span>
            </div>
            <div class="mt-4 space-y-2.5 text-[13px]" style="color:#5b3a24;">
                @if($fmt($todayTiming->morning_open))
                    <div class="flex justify-between"><span>{{ __('footer.morning') }}</span><span class="font-bold" style="color:#7A1E1E;">{{ $fmt($todayTiming->morning_open) }}</span></div>
                @endif
                @if($fmt($todayTiming->aarti_morning))
                    <div class="flex justify-between"><span>{{ __('footer.morning_aarti') }}</span><span class="font-bold" style="color:#7A1E1E;">{{ $fmt($todayTiming->aarti_morning) }}</span></div>
                @endif
                @if($fmt($todayTiming->aarti_evening))
                    <div class="flex justify-between" style="color:#a15c12;"><span class="font-semibold">{{ __('footer.evening_aarti') }}</span><span class="font-bold">{{ $fmt($todayTiming->aarti_evening) }}</span></div>
                @endif
                @if($fmt($todayTiming->evening_close))
                    <div class="flex justify-between"><span>{{ __('home.temple_closes') }}</span><span class="font-bold" style="color:#7A1E1E;">{{ $fmt($todayTiming->evening_close) }}</span></div>
                @endif
            </div>
            <a href="{{ route('darshan') }}"
               class="block mt-5 text-center px-4 py-2.5 rounded-full text-xs font-extrabold text-white transition hover:opacity-90"
               style="background:#E8751A;">{{ __('home.go_to_darshan') }} →</a>
        </div>
    </div>
@endif
