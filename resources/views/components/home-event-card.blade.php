@props(['event'])

<a href="{{ route('events.show', $event) }}"
   class="card-sacred p-6 inner-glow h-full flex gap-4 group transition hover:-translate-y-0.5">

    {{-- Date chip --}}
    <div class="w-16 h-16 rounded-2xl flex flex-col items-center justify-center flex-shrink-0 border"
         style="background: linear-gradient(145deg, #FFFCF5, #FBF5EA); border-color: rgba(200,148,52,0.35);">
        <span class="text-xl font-black leading-none" style="color: #7A1E1E;">{{ $event->start_date->format('d') }}</span>
        <span class="text-[10px] font-bold uppercase mt-0.5" style="color: #C45F12;">{{ $event->start_date->format('M') }}</span>
    </div>

    {{-- Body --}}
    <div class="flex-1 min-w-0">
        <h3 class="font-bold text-lg group-hover:underline truncate" style="color: #7A1E1E;">{{ $event->title }}</h3>
        @if($event->location)
            <p class="text-xs mt-1 flex items-center gap-1" style="color: #8A7860;">
                <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="truncate">{{ $event->location }}</span>
            </p>
        @endif
        @if($event->description)
            <p class="text-sm mt-2 line-clamp-2" style="color: #5E4F3D;">
                {!! strip_tags($event->description) !!}
            </p>
        @endif
    </div>
</a>
