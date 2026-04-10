{{-- Sticky Sidebar for Project Show Page --}}
<div class="lg:sticky lg:top-24 space-y-6" x-data="campaignDonation()">

    {{-- ---- Progress Card ---- --}}
    <div class="card-sacred p-6">
        {{-- Progress Bar --}}
        <div class="mb-4">
            <div class="w-full bg-amber-900/30 rounded-full h-4 overflow-hidden">
                <div class="bg-gradient-to-r from-amber-600 via-amber-500 to-amber-400 h-4 rounded-full transition-all duration-1000 relative overflow-hidden"
                     style="width: {{ $pct }}%">
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent animate-pulse"></div>
                </div>
            </div>
        </div>

        {{-- Amounts --}}
        <div class="flex items-baseline gap-2 mb-1">
            <span class="text-2xl font-bold text-gold">₹{{ number_format($raised) }}</span>
            <span class="text-sm text-amber-100/30">એકત્ર</span>
        </div>
        <div class="text-sm text-amber-100/40 mb-3">
            ₹{{ number_format($goal) }} લક્ષ્યમાંથી
        </div>

        {{-- Stats Row --}}
        <div class="flex items-center gap-4 mb-4">
            <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-amber-900/40 text-amber-400">{{ $pct }}%</span>
            <span class="text-sm text-amber-100/50">
                <svg class="w-4 h-4 inline mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ $project->donor_count ?? 0 }} દાનકર્તા
            </span>
        </div>

        {{-- Status Badges --}}
        @if($isGoalReached)
            <div class="px-4 py-2 rounded-lg bg-green-900/30 border border-green-700/30 text-green-400 text-sm font-semibold text-center mb-4">
                લક્ષ્ય પ્રાપ્ત! 🎉
            </div>
        @endif
        @if($isEnded)
            <div class="px-4 py-2 rounded-lg bg-amber-900/30 border border-amber-700/30 text-amber-400 text-sm font-semibold text-center mb-4">
                સમાપ્ત
            </div>
        @endif

        {{-- ---- Donation Form ---- --}}
        @if($isEnded)
            <div class="px-4 py-3 rounded-lg bg-amber-900/20 border border-amber-800/20 text-amber-100/50 text-sm text-center">
                આ પ્રોજેક્ટ સમાપ્ત થયો છે
            </div>
        @else
            {{-- Preset Amounts --}}
            <div class="mb-4">
                <label class="block text-sm font-medium text-amber-600 mb-2">રકમ પસંદ કરો</label>
                <div class="grid grid-cols-3 gap-2">
                    @foreach([501, 1100, 2100, 5100, 11000] as $preset)
                        <button type="button"
                            @click="amount = {{ $preset }}; customAmount = ''"
                            :class="amount === {{ $preset }} && !customAmount ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 border-amber-500 font-bold' : 'bg-transparent text-amber-100/60 border-amber-800/30 hover:border-amber-600'"
                            class="py-2.5 border rounded-lg text-sm font-semibold transition">
                            ₹{{ number_format($preset) }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Custom Amount --}}
            <div class="mb-4">
                <label class="block text-sm font-medium text-amber-600 mb-1">અથવા કસ્ટમ રકમ</label>
                <div class="flex">
                    <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-amber-800/30 bg-amber-900/20 text-amber-500 font-medium text-sm">₹</span>
                    <input type="number" min="1" placeholder="રકમ દાખલ કરો"
                        x-model="customAmount"
                        @input="if(customAmount) amount = parseInt(customAmount)"
                        class="flex-1 block w-full rounded-r-lg bg-transparent border-amber-800/30 text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20">
                </div>
            </div>

            {{-- Anonymous --}}
            <div class="mb-5">
                <label class="flex items-center gap-2">
                    <input type="checkbox" x-model="anonymous" class="rounded border-amber-800/40 bg-transparent text-amber-500 focus:ring-amber-600/20">
                    <span class="text-sm text-amber-100/60">ગુપ્ત દાન (નામ જાહેર ન કરો)</span>
                </label>
            </div>

            {{-- Submit --}}
            @auth('devotee')
                <form method="POST" action="{{ route('donate.create') }}">
                    @csrf
                    <input type="hidden" name="amount" :value="amount">
                    <input type="hidden" name="donation_type" value="campaign">
                    <input type="hidden" name="campaign_id" value="{{ $project->id }}">
                    <input type="hidden" name="anonymous" :value="anonymous ? 1 : 0">

                    <button type="submit"
                        :disabled="!amount || amount < 1"
                        class="w-full py-3 btn-divine disabled:opacity-40 disabled:cursor-not-allowed text-base font-bold">
                        દાન કરો — ₹<span x-text="amount ? amount.toLocaleString('en-IN') : '0'"></span>
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="block w-full text-center py-3 btn-divine text-base font-bold">
                    દાન કરવા લૉગિન કરો
                </a>
            @endauth
        @endif

        @if($errors->any())
            <div class="bg-red-950/30 border border-red-800/30 text-red-300 px-4 py-3 rounded-lg mt-4 text-sm">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ---- Share Buttons ---- --}}
    <div class="card-sacred p-6">
        <h3 class="text-sm font-semibold text-gold mb-3">શેર કરો</h3>
        <div class="flex flex-wrap gap-2">
            {{-- WhatsApp --}}
            <a href="https://api.whatsapp.com/send?text={{ $shareTitle }}%20-%20{{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-green-700/20 border border-green-700/30 text-green-400 hover:bg-green-700/30 transition text-xs font-medium">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                WhatsApp
            </a>

            {{-- Facebook --}}
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-blue-700/20 border border-blue-700/30 text-blue-400 hover:bg-blue-700/30 transition text-xs font-medium">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                Facebook
            </a>

            {{-- Twitter/X --}}
            <a href="https://twitter.com/intent/tweet?text={{ $shareTitle }}&url={{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-stone-700/20 border border-stone-600/30 text-stone-300 hover:bg-stone-700/30 transition text-xs font-medium">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                X
            </a>

            {{-- LinkedIn --}}
            <a href="https://www.linkedin.com/shareArticle?mini=true&url={{ $shareUrl }}&title={{ $shareTitle }}"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-blue-600/20 border border-blue-600/30 text-blue-300 hover:bg-blue-600/30 transition text-xs font-medium">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                LinkedIn
            </a>

            {{-- Copy Link --}}
            <button @click="copyLink()"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-700/20 border border-amber-700/30 text-amber-400 hover:bg-amber-700/30 transition text-xs font-medium">
                <svg x-show="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                <svg x-show="copied" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span x-text="copied ? 'Copied!' : 'Copy Link'"></span>
            </button>
        </div>
    </div>

    {{-- ---- Campaign Details ---- --}}
    <div class="card-sacred p-6">
        <h3 class="text-sm font-semibold text-gold mb-3">અભિયાન વિગતો</h3>
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between">
                <dt class="text-amber-100/40">શરૂઆત તારીખ</dt>
                <dd class="text-amber-100/70 font-medium">{{ $project->start_date->format('d/m/Y') }}</dd>
            </div>
            @if($project->end_date)
                <div class="flex justify-between">
                    <dt class="text-amber-100/40">અંતિમ તારીખ</dt>
                    <dd class="text-amber-100/70 font-medium">{{ $project->end_date->format('d/m/Y') }}</dd>
                </div>
            @else
                <div class="flex justify-between">
                    <dt class="text-amber-100/40">અંતિમ તારીખ</dt>
                    <dd class="text-amber-100/50 text-xs">સમય-મર્યાદા નથી</dd>
                </div>
            @endif
        </dl>
    </div>

</div>

@once
@push('scripts')
<script>
function campaignDonation() {
    return {
        amount: 1100,
        customAmount: '',
        anonymous: false,
        copied: false,

        copyLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            });
        }
    };
}
</script>
@endpush
@endonce
