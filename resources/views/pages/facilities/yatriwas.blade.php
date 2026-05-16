@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => 'સુવિધાઓ'],
        ['label' => 'યાત્રીવાસ'],
    ]"
    title="યાત્રીવાસ"
    subtitle="યાત્રિક નિવાસ સેવા — દૂર-દૂરથી આવતા યાત્રિકો માટે વિશ્રામ-ગૃહ" />

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="card-sacred p-6 sm:p-10 mb-8">
        <p class="text-amber-100/60 leading-relaxed mb-8">
            શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ દ્વારા દૂર-દૂરથી પધારતા યાત્રિકોના આરામ માટે
            મંદિર પરિસરની બાજુમાં યાત્રીવાસની સુવિધા ઉપલબ્ધ કરાવાય છે.
            ભાવ-ભક્તિ સહ દર્શન કરવા આવનાર સૌ ભક્તોનું હાર્દિક સ્વાગત છે.
        </p>

        {{-- Room Types --}}
        <h2 class="text-xl font-bold text-gold mb-5">રૂમના પ્રકાર</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">

            <div class="border border-amber-800/30 rounded-xl p-5 hover:border-amber-600/50 transition bg-amber-900/10">
                <div class="w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">સામૂહિક હૉલ (ડોર્મિટરી)</h3>
                <p class="text-xs text-amber-100/40">૧૦-૨૦ વ્યક્તિ માટે. પ્રાથમિક સુવિધા. નામ-માત્ર ભાડું.</p>
                <p class="text-amber-400 font-bold text-sm mt-2">₹ ૫૦ / રાત (પ્રતિ વ્યક્તિ)</p>
            </div>

            <div class="border border-amber-600/40 rounded-xl p-5 bg-amber-900/20 relative">
                <span class="absolute top-3 right-3 px-2 py-0.5 bg-amber-600/80 text-stone-900 text-xs font-bold rounded-full">લોકપ્રિય</span>
                <div class="w-10 h-10 bg-amber-800/40 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">ડબલ રૂમ</h3>
                <p class="text-xs text-amber-100/40">૨ વ્યક્તિ માટે. પલંગ, પંખો, અટેચ્ડ બાથરૂમ.</p>
                <p class="text-gold font-bold text-sm mt-2">₹ ૩૦૦ / રાત</p>
            </div>

            <div class="border border-amber-800/30 rounded-xl p-5 hover:border-amber-600/50 transition bg-amber-900/10">
                <div class="w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">ફેમિલી સ્યૂટ</h3>
                <p class="text-xs text-amber-100/40">૪-૬ વ્યક્તિ માટે. ૨ પલંગ, AC, અટેચ્ડ બાથરૂમ.</p>
                <p class="text-amber-400 font-bold text-sm mt-2">₹ ૮૦૦ / રાત</p>
            </div>

        </div>

        {{-- Booking Info --}}
        <h2 class="text-xl font-bold text-gold mb-4">બુકિંગ માહિતી</h2>
        <div class="bg-amber-900/20 rounded-xl p-5 text-sm text-amber-100/60 space-y-3 border border-amber-800/30">
            <p><strong class="text-amber-100/70">બુકિંગ પ્રક્રિયા:</strong> ઓનલાઈન અથવા મંદિર કાર્યાલયમાં રૂબરૂ બુકિંગ કરાવી શકાય છે.</p>
            <p><strong class="text-amber-100/70">અગ્રિમ બુકિંગ:</strong> ઉત્સવ અને વિશેષ પ્રસંગો માટે ઓછામાં ઓછા ૭ દિવસ અગ્રિમ બુકિંગ ઈચ્છનીય છે.</p>
            <p><strong class="text-amber-100/70">ચેક-ઈન / ચેક-આઉટ:</strong> ચેક-ઈન બપોરે ૧૨:૦૦ | ચેક-આઉટ સવારે ૧૦:૦૦</p>
            <p><strong class="text-amber-100/70">ઓળખપત્ર:</strong> આધાર કાર્ડ અથવા સરકાર-માન્ય કોઈપણ ID ફરજિયાત છે.</p>
            <p><strong class="text-amber-100/70">સંપર્ક — મંદિર ઓફિસ:</strong> +91 XXXXX XXXXX | Email: trust@pataliyahanuman.org</p>
        </div>

    </div>

    {{-- Amenities --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">સુવિધાઓ</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach(['ફ્રી Wi-Fi', 'ભોજનાલય', '૨૪ કલાક પાણી', 'પાર્કિંગ', 'ગીઝર', 'લોન્ડ્રી', 'લોકર', 'CCTV સુરક્ષા'] as $amenity)
            <div class="flex items-center gap-2 text-sm text-amber-100/60">
                <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ $amenity }}
            </div>
            @endforeach
        </div>
    </div>

</div>

@endsection
