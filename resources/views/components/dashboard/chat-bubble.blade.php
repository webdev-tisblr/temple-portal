@props(['author', 'body', 'at' => null, 'mine' => false])
{{--
    One turn in a contact conversation. The devotee's own turns sit right and
    the trust's sit left, which is the only cue that reads the same in all
    three languages without a label.

    Colours are inline hex, matching the rest of the dashboard: these pages are
    parchment-on-light and the amber utility classes belong to the dark public
    site (see DashboardViewsTest's legacy-class guard).
--}}
<div @class(['flex', 'justify-end' => $mine, 'justify-start' => ! $mine])>
    <div class="max-w-[85%] rounded-xl px-4 py-3 border"
         style="{{ $mine
            ? 'background:#FBF6EC; border-color:#E4D5BC;'
            : 'background:#FFFFFF; border-color:#C45F12;' }}">
        <div class="mb-1 flex items-baseline justify-between gap-4">
            <span class="text-xs font-semibold" style="color: #C45F12;">{{ $author }}</span>
            <span class="text-[11px]" style="color: #8A7860;">{{ $at?->format('d M Y, h:i A') }}</span>
        </div>
        <p class="whitespace-pre-line text-sm" style="color: #4A3728;">{{ $body }}</p>
    </div>
</div>
