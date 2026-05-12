@php
    // Pull everything from SystemSetting so admins can edit footer
    // copy without a code change.
    use App\Models\SystemSetting;
    use App\Models\DarshanTiming;

    $trustName    = SystemSetting::getValue('trust_name', 'શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
    $trustAddress = SystemSetting::getValue('trust_address', 'અંતરજાલ, ગાંધીધામ, કચ્છ — 370110');
    $trustPhone   = SystemSetting::getValue('trust_phone');
    $trustEmail   = SystemSetting::getValue('trust_email');
    $trustWhatsApp= SystemSetting::getValue('trust_whatsapp');
    $trust80g     = SystemSetting::getValue('trust_80g_reg_no');
    $mapUrl       = SystemSetting::getValue('trust_map_url');
    $fbUrl        = SystemSetting::getValue('trust_facebook_url');
    $igUrl        = SystemSetting::getValue('trust_instagram_url');
    $ytUrl        = SystemSetting::getValue('trust_youtube_url');

    // Today's darshan snapshot — cached in the model already so this
    // is cheap to call from every page render.
    $todayTiming  = DarshanTiming::where('is_active', true)
        ->where('day_type', 'regular')
        ->first();
@endphp

<footer class="border-t border-[rgba(122,30,30,0.12)]" style="background: linear-gradient(180deg, #F4EAD5, #FBF5EA);">

    {{-- Ornamental top --}}
    <div class="flex justify-center -mt-px">
        <div class="w-32 h-px" style="background: linear-gradient(to right, transparent, #c49a2a, transparent);"></div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10">

            {{-- ── 1. Trust identity ───────────────────────────────── --}}
            <div>
                <div class="flex items-center gap-3 mb-4">
                    <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
                         alt="{{ $trustName }}"
                         class="w-12 h-12 rounded-full border diya-glow object-cover"
                         style="border-color: rgba(200,148,52,0.45); box-shadow: 0 0 14px rgba(196,154,42,0.25);">
                    <div>
                        <h3 class="text-base font-bold leading-tight" style="color: #7A1E1E;">શ્રી પાતળિયા હનુમાનજી</h3>
                        <p class="text-[10px] tracking-widest uppercase font-semibold mt-0.5" style="color: #C45F12;">સેવા ટ્રસ્ટ &bull; અંતરજાલ</p>
                    </div>
                </div>
                <p class="text-sm leading-relaxed" style="color: #5E4F3D;">
                    ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ભક્તિ, સેવા અને સમર્પણ સાથે મંદિરનું વ્યવસ્થાપન ટ્રસ્ટ દ્વારા થાય છે.
                </p>
                @if($trust80g)
                    <p class="mt-3 text-xs" style="color: #8A7860;">
                        <span class="font-semibold" style="color: #7A1E1E;">80G:</span> {{ $trust80g }}
                    </p>
                @endif
            </div>

            {{-- ── 2. Quick links ──────────────────────────────────── --}}
            <div>
                <h3 class="text-xs uppercase tracking-widest font-bold mb-4" style="color: #C45F12;">ઝડપી લિંક્સ</h3>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="{{ route('seva.index') }}"     style="color: #3E3226;" class="hover:underline">સેવા અને પૂજા</a></li>
                    <li><a href="{{ route('donate') }}"        style="color: #3E3226;" class="hover:underline">ઓનલાઈન દાન</a></li>
                    <li><a href="{{ route('darshan') }}"       style="color: #3E3226;" class="hover:underline">દર્શન સમય</a></li>
                    <li><a href="{{ route('events.index') }}"  style="color: #3E3226;" class="hover:underline">કાર્યક્રમો</a></li>
                    <li><a href="{{ route('projects.index') }}" style="color: #3E3226;" class="hover:underline">સેવા પ્રોજેક્ટ્સ</a></li>
                    <li><a href="{{ route('gallery') }}"        style="color: #3E3226;" class="hover:underline">ફોટો ગેલેરી</a></li>
                    <li><a href="{{ route('halls.index') }}"    style="color: #3E3226;" class="hover:underline">હોલ બુકિંગ</a></li>
                    <li><a href="{{ route('store.index') }}"    style="color: #3E3226;" class="hover:underline">મંદિર સ્ટોર</a></li>
                </ul>
            </div>

            {{-- ── 3. Reach us ─────────────────────────────────────── --}}
            <div>
                <h3 class="text-xs uppercase tracking-widest font-bold mb-4" style="color: #C45F12;">સંપર્ક</h3>
                <ul class="space-y-3 text-sm" style="color: #3E3226;">
                    <li class="flex gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>{{ $trustAddress }}</span>
                    </li>
                    @if($trustPhone)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <a href="tel:{{ $trustPhone }}" class="hover:underline">{{ $trustPhone }}</a>
                        </li>
                    @endif
                    @if($trustWhatsApp)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24" style="color: #25D366;">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884"/>
                            </svg>
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $trustWhatsApp) }}"
                               target="_blank" rel="noopener"
                               class="hover:underline">WhatsApp</a>
                        </li>
                    @endif
                    @if($trustEmail)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <a href="mailto:{{ $trustEmail }}" class="hover:underline">{{ $trustEmail }}</a>
                        </li>
                    @endif
                    @if($mapUrl)
                        <li class="flex gap-2.5">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m-6 3l6-3"/>
                            </svg>
                            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="hover:underline">Google Maps</a>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- ── 4. Darshan timings + socials ────────────────────── --}}
            <div>
                @if($todayTiming)
                    <h3 class="text-xs uppercase tracking-widest font-bold mb-4" style="color: #C45F12;">દર્શન સમય</h3>
                    <ul class="space-y-2 text-sm mb-6" style="color: #3E3226;">
                        @if($todayTiming->morning_open && $todayTiming->morning_close)
                            <li class="flex justify-between gap-3">
                                <span class="flex items-center gap-1.5"><span>☀️</span> સવારે</span>
                                <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->morning_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($todayTiming->morning_close)->format('h:i A') }}</span>
                            </li>
                        @endif
                        @if($todayTiming->evening_open && $todayTiming->evening_close)
                            <li class="flex justify-between gap-3">
                                <span class="flex items-center gap-1.5"><span>🌙</span> સાંજે</span>
                                <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->evening_open)->format('h:i') }} – {{ \Carbon\Carbon::parse($todayTiming->evening_close)->format('h:i A') }}</span>
                            </li>
                        @endif
                        @if($todayTiming->aarti_morning)
                            <li class="flex justify-between gap-3 pt-1 border-t" style="border-color: rgba(122,30,30,0.10);">
                                <span class="flex items-center gap-1.5"><span>🪔</span> સવારની આરતી</span>
                                <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->aarti_morning)->format('h:i A') }}</span>
                            </li>
                        @endif
                        @if($todayTiming->aarti_evening)
                            <li class="flex justify-between gap-3">
                                <span class="flex items-center gap-1.5"><span>🪔</span> સાંજની આરતી</span>
                                <span class="font-semibold tabular-nums">{{ \Carbon\Carbon::parse($todayTiming->aarti_evening)->format('h:i A') }}</span>
                            </li>
                        @endif
                    </ul>
                @endif

                <h3 class="text-xs uppercase tracking-widest font-bold mb-3" style="color: #C45F12;">અમારી સાથે જોડાઓ</h3>
                <div class="flex gap-2.5">
                    @if($fbUrl)
                        <a href="{{ $fbUrl }}" target="_blank" rel="noopener" aria-label="Facebook"
                           class="w-10 h-10 rounded-xl border flex items-center justify-center transition hover:-translate-y-0.5"
                           style="background: #FFFCF5; border-color: rgba(122,30,30,0.15); color: #1877F2;">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </a>
                    @endif
                    @if($igUrl)
                        <a href="{{ $igUrl }}" target="_blank" rel="noopener" aria-label="Instagram"
                           class="w-10 h-10 rounded-xl border flex items-center justify-center transition hover:-translate-y-0.5"
                           style="background: #FFFCF5; border-color: rgba(122,30,30,0.15); color: #E4405F;">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                        </a>
                    @endif
                    @if($ytUrl)
                        <a href="{{ $ytUrl }}" target="_blank" rel="noopener" aria-label="YouTube"
                           class="w-10 h-10 rounded-xl border flex items-center justify-center transition hover:-translate-y-0.5"
                           style="background: #FFFCF5; border-color: rgba(122,30,30,0.15); color: #FF0000;">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                        </a>
                    @endif
                    @if(!$fbUrl && !$igUrl && !$ytUrl)
                        <p class="text-xs italic" style="color: #8A7860;">હાલ ઉપલબ્ધ નથી</p>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- ── Bottom bar ──────────────────────────────────────────── --}}
    <div class="border-t" style="border-color: rgba(122,30,30,0.10);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs" style="color: #5E4F3D;">
            <p>&copy; {{ date('Y') }} {{ $trustName }} — સર્વ અધિકાર સુરક્ષિત</p>
            <p>
                Crafted with <span style="color: #C45F12;">♥</span> by
                <a href="https://theinternetstore.in" target="_blank" rel="noopener"
                   class="font-semibold hover:underline"
                   style="color: #7A1E1E;">The Internet Store</a>
            </p>
        </div>
    </div>
</footer>
