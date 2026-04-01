@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-amber-100/30">Suvidha</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">ભોજનાલય</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">ભોજનાલય</h1>
        <p class="mt-2 divine-subtext">Shree Pataliya Hanumanji Dham — Bhojan Seva</p>
    </div>
</section>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro Card --}}
    <div class="card-sacred p-6 sm:p-10 mb-8">
        <div class="flex items-start gap-4 mb-6">
            <div class="flex-shrink-0 w-12 h-12 bg-amber-900/30 rounded-xl flex items-center justify-center">
                <svg class="w-7 h-7 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gold">Prasad Bhojanaalay</h2>
                <p class="text-amber-100/40 text-sm mt-1">Mandir Trust dvaara bhaktono ane yatrionu free / nominal charge bhojan seva.</p>
            </div>
        </div>

        <p class="text-amber-100/60 leading-relaxed mb-6">
            Shree Pataliya Hanumanji Seva Trust dvaara mandir parisar ma ek swachha ane sukhadayi bhojanaalay
            chalavaama aave chhe. Yaha bhaktono ane dur-durathi aavelaa yatrionu sattvik prasad bhojan seva
            aapaavaama aave chhe. Bhojanaalay purnata community-funded chhe ane daan-bhav par nirvar karuchhe.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">

            {{-- Timings --}}
            <div class="bg-amber-900/20 rounded-xl p-5 border border-amber-800/30">
                <h3 class="font-semibold text-gold mb-3 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Samay (Timings)
                </h3>
                <ul class="space-y-2 text-sm text-amber-100/60">
                    <li class="flex justify-between"><span>Savaarnu bhojan</span><span class="font-medium text-amber-100/70">07:30 AM – 10:00 AM</span></li>
                    <li class="flex justify-between"><span>Dopaarnu bhojan</span><span class="font-medium text-amber-100/70">12:00 PM – 02:30 PM</span></li>
                    <li class="flex justify-between"><span>Sanjnu bhojan</span><span class="font-medium text-amber-100/70">07:00 PM – 09:00 PM</span></li>
                </ul>
            </div>

            {{-- Capacity --}}
            <div class="bg-amber-900/20 rounded-xl p-5 border border-amber-800/30">
                <h3 class="font-semibold text-gold mb-3 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Khamta (Capacity)
                </h3>
                <ul class="space-y-2 text-sm text-amber-100/60">
                    <li class="flex justify-between"><span>Eksamaye capacity</span><span class="font-medium text-amber-100/70">200+ bhakto</span></li>
                    <li class="flex justify-between"><span>Festival capacity</span><span class="font-medium text-amber-100/70">500+ bhakto</span></li>
                    <li class="flex justify-between"><span>Special pankti</span><span class="font-medium text-amber-100/70">Booking required</span></li>
                </ul>
            </div>

        </div>
    </div>

    {{-- Menu / Food Info --}}
    <div class="card-sacred p-6 sm:p-8 mb-8">
        <h2 class="text-xl font-bold text-gold mb-4">Bhojan Variety</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🍛</p>
                <p class="font-semibold text-amber-100/70 text-sm">Dal-bhat-rotli</p>
                <p class="text-xs text-amber-100/40 mt-1">Rojnuj sattvik bhojan</p>
            </div>
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🍮</p>
                <p class="font-semibold text-amber-100/70 text-sm">Prasad / Meethu</p>
                <p class="text-xs text-amber-100/40 mt-1">Puja baad prasad vitaran</p>
            </div>
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🥣</p>
                <p class="font-semibold text-amber-100/70 text-sm">Festival Special</p>
                <p class="text-xs text-amber-100/40 mt-1">Utsav pra bhojan</p>
            </div>
        </div>
    </div>

    {{-- Rules --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">Bhojanaalay Niyamo</h2>
        <ul class="space-y-3">
            @foreach([
                'Bhojanaalay ma pravesh karta pahelaaa haath-paav dhova.',
                'Bhojan samay maunat / shant vatavaran jalavvu.',
                'Plate ma jetlu khavanu hoy tevu j levo — waste na karvo.',
                'Bhojan pachhi plate aapelo jagye muki devo.',
                'Mobile phone bhojan samay band / silent rakhavo.',
                'Bhojanaalay no upyog faqat Trust-registered yatri / bhakto mate.',
            ] as $rule)
            <li class="flex items-start gap-3 text-sm text-amber-100/60">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ $rule }}
            </li>
            @endforeach
        </ul>
    </div>

</div>

@endsection
