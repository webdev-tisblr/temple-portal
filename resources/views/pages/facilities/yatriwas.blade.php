@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-amber-100/30">Suvidha</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">યાત્રીવાસ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">યાત્રીવાસ</h1>
        <p class="mt-2 divine-subtext">Pilgrim Rest House — Yatri Nivas Seva</p>
    </div>
</section>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="card-sacred p-6 sm:p-10 mb-8">
        <p class="text-amber-100/60 leading-relaxed mb-8">
            Shree Pataliya Hanumanji Seva Trust dvaara dur-durathi aavelaa yatrionu aaram mate
            mandir parisar ni baaju ma yatriwas ni suvidha aapaavaama aave chhe.
            Sarvani saugandh bhav-bhakti sathe darshan karvaani tanka no aabhar.
        </p>

        {{-- Room Types --}}
        <h2 class="text-xl font-bold text-gold mb-5">Room Types</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">

            <div class="border border-amber-800/30 rounded-xl p-5 hover:border-amber-600/50 transition bg-amber-900/10">
                <div class="w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">Dormitory (Saamuhik)</h3>
                <p class="text-xs text-amber-100/40">10-20 vyakti mata. Basic suvidha. Nominal charge.</p>
                <p class="text-amber-400 font-bold text-sm mt-2">Rs. 50 / raat per person</p>
            </div>

            <div class="border border-amber-600/40 rounded-xl p-5 bg-amber-900/20 relative">
                <span class="absolute top-3 right-3 px-2 py-0.5 bg-amber-600/80 text-stone-900 text-xs font-bold rounded-full">Popular</span>
                <div class="w-10 h-10 bg-amber-800/40 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">Double Room</h3>
                <p class="text-xs text-amber-100/40">2 vyakti mata. Bed, fan, attached toilet.</p>
                <p class="text-gold font-bold text-sm mt-2">Rs. 300 / raat</p>
            </div>

            <div class="border border-amber-800/30 rounded-xl p-5 hover:border-amber-600/50 transition bg-amber-900/10">
                <div class="w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="font-semibold text-amber-100/70 mb-1">Family Suite</h3>
                <p class="text-xs text-amber-100/40">4-6 vyakti mata. 2 beds, AC, attached bathroom.</p>
                <p class="text-amber-400 font-bold text-sm mt-2">Rs. 800 / raat</p>
            </div>

        </div>

        {{-- Booking Info --}}
        <h2 class="text-xl font-bold text-gold mb-4">Booking Mahiti</h2>
        <div class="bg-amber-900/20 rounded-xl p-5 text-sm text-amber-100/60 space-y-3 border border-amber-800/30">
            <p><strong class="text-amber-100/70">Booking process:</strong> Online booking ya mandir karyalay ma direct booking karavi shakay.</p>
            <p><strong class="text-amber-100/70">Advance booking:</strong> Festival / special occasion ma min. 7 divas advance booking recommended.</p>
            <p><strong class="text-amber-100/70">Check-in / Check-out:</strong> Check-in: 12:00 PM | Check-out: 10:00 AM</p>
            <p><strong class="text-amber-100/70">ID Proof:</strong> Aadhar card / koi pan government ID jaruri chhe.</p>
            <p><strong class="text-amber-100/70">સંપર્ક: મંદિર ઓફિસ:</strong> +91 XXXXX XXXXX | Email: trust@pataliyahanuman.org</p>
        </div>

    </div>

    {{-- Amenities --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">Suvidha (Amenities)</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach(['Free Wi-Fi', 'Bhojanaalay', '24-hr Pani', 'Parking', 'Geyser', 'Laundry', 'Locker', 'CCTV Security'] as $amenity)
            <div class="flex items-center gap-2 text-sm text-amber-100/60">
                <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ $amenity }}
            </div>
            @endforeach
        </div>
    </div>

</div>

@endsection
