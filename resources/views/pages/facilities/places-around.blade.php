@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => 'સુવિધાઓ'],
        ['label' => 'આસપાસના સ્થળો'],
    ]"
    title="આસપાસના સ્થળો"
    subtitle="મંદિર આસપાસના પ્રસિદ્ધ સ્થાનો" />

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    <p class="text-amber-100/60 leading-relaxed mb-10">
        શ્રી પાતળિયા હનુમાનજી ધામ ગાંધીધામ-કચ્છમાં આવેલું છે.
        આસપાસ અનેક પ્રસિદ્ધ ધર્મ-સ્થાનો, ઐતિહાસિક સ્થળો અને કુદરતી સૌંદર્યથી
        સભર સ્થાનો છે, જ્યાં યાત્રિકો અવશ્ય મુલાકાત લઈ શકે છે.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        @php
        $places = [
            [
                'name'     => 'ગાંધીધામ રેલવે સ્ટેશન',
                'distance' => '~૩ કિ.મી.',
                'type'     => 'પરિવહન',
                'desc'     => 'ગાંધીધામ જંકશન — કચ્છનું સૌથી મોટું રેલવે સ્ટેશન. મુંબઈ, અમદાવાદ અને દિલ્હી સાથે સીધી ટ્રેનો.',
                'icon'     => 'M3 10h18M3 14h18M8 6V3m8 3V3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            ],
            [
                'name'     => 'કંડલા પોર્ટ (દીનદયાલ પોર્ટ)',
                'distance' => '~૧૨ કિ.મી.',
                'type'     => 'ઐતિહાસિક',
                'desc'     => 'ભારતનું સૌથી જૂનું બંદર. ઔદ્યોગિક ઇતિહાસ અને સૂર્યોદય માટે પ્રખ્યાત.',
                'icon'     => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
            ],
            [
                'name'     => 'પિંગળેશ્વર બીચ',
                'distance' => '~૩૫ કિ.મી.',
                'type'     => 'કુદરતી',
                'desc'     => 'એકાંત અને સુંદર બીચ. સ્વચ્છ રેતી, સૂર્યાસ્તના નયનરમ્ય દ્રશ્યો — ધ્યાન અને વિશ્રામ માટે અનુકૂળ.',
                'icon'     => 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
            ],
            [
                'name'     => 'માતાનો મઢ (આશાપુરા માતા)',
                'distance' => '~૮૫ કિ.મી.',
                'type'     => 'ધાર્મિક',
                'desc'     => 'કચ્છની કુળદેવી આશાપુરા માતાનું પ્રસિદ્ધ મંદિર. નવરાત્રિ દરમિયાન લાખો ભક્તો દર્શન માટે પધારે છે.',
                'icon'     => 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707',
            ],
            [
                'name'     => 'ભુજ શહેર (કચ્છનું મુખ્ય મથક)',
                'distance' => '~૫૫ કિ.મી.',
                'type'     => 'નગર / પ્રવાસન',
                'desc'     => 'કચ્છનું મુખ્ય મથક. પ્રાગ મહેલ, આયના મહેલ, કચ્છ મ્યુઝિયમ અને જગપ્રસિદ્ધ કચ્છી હસ્તકલા બજાર.',
                'icon'     => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            ],
            [
                'name'     => 'માંડવી બીચ',
                'distance' => '~૬૦ કિ.મી.',
                'type'     => 'કુદરતી',
                'desc'     => 'કચ્છનો સૌથી પ્રસિદ્ધ બીચ. સૂર્યાસ્ત, ઊંટ-સવારી અને વિજય-વિલાસ પેલેસ સાથેનો જાણીતો પ્રવાસ-સ્થળ.',
                'icon'     => 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
            ],
        ];
        @endphp

        @foreach($places as $place)
        <div class="card-sacred p-6 flex gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-amber-900/30 rounded-xl flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $place['icon'] }}"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h3 class="font-bold text-gold text-lg leading-tight">{{ $place['name'] }}</h3>
                    <span class="flex-shrink-0 px-2 py-0.5 bg-amber-900/30 text-amber-400 text-xs font-medium rounded-full border border-amber-800/30">{{ $place['distance'] }}</span>
                </div>
                <span class="inline-block px-2 py-0.5 bg-amber-900/20 text-amber-100/40 text-xs rounded-full mb-2">{{ $place['type'] }}</span>
                <p class="text-sm text-amber-100/50 leading-relaxed">{{ $place['desc'] }}</p>
            </div>
        </div>
        @endforeach

    </div>

    <div class="mt-10 bg-amber-900/20 border border-amber-800/30 rounded-xl p-5 text-sm text-amber-100/50">
        <p><strong class="text-amber-100/70">નોંધ:</strong> દર્શાવેલ અંતર અંદાજિત છે. વાસ્તવિક પ્રવાસ-સમય ટ્રાફિક પર આધાર રાખે છે. યોગ્ય આયોજન સાથે મુલાકાત લેવી.</p>
    </div>

</div>

@endsection
