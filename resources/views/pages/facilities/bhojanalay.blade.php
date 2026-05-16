@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => 'સુવિધાઓ'],
        ['label' => 'ભોજનાલય'],
    ]"
    title="ભોજનાલય"
    subtitle="શ્રી પાતાળિયા હનુમાનજી ધામ — પ્રસાદ-ભોજન સેવા" />

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
                <h2 class="text-xl font-bold text-gold">પ્રસાદ ભોજનાલય</h2>
                <p class="text-amber-100/40 text-sm mt-1">મંદિર ટ્રસ્ટ દ્વારા ભક્તો અને યાત્રિકો માટે નિ:શુલ્ક / નામ-માત્ર ભાડે ભોજન સેવા.</p>
            </div>
        </div>

        <p class="text-amber-100/60 leading-relaxed mb-6">
            શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ દ્વારા મંદિર પરિસરમાં એક સ્વચ્છ અને સુખદાયી ભોજનાલય
            ચાલે છે. અહીં ભક્તો અને દૂર-દૂરથી પધારતા યાત્રિકોને સાત્ત્વિક પ્રસાદ-ભોજન સેવા
            ઉપલબ્ધ કરાવાય છે. ભોજનાલય સંપૂર્ણપણે દાનદારોના સહયોગથી ચાલે છે.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">

            {{-- Timings --}}
            <div class="bg-amber-900/20 rounded-xl p-5 border border-amber-800/30">
                <h3 class="font-semibold text-gold mb-3 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    સમય
                </h3>
                <ul class="space-y-2 text-sm text-amber-100/60">
                    <li class="flex justify-between"><span>સવારનું ભોજન</span><span class="font-medium text-amber-100/70">07:30 – 10:00</span></li>
                    <li class="flex justify-between"><span>બપોરનું ભોજન</span><span class="font-medium text-amber-100/70">12:00 – 02:30</span></li>
                    <li class="flex justify-between"><span>સાંજનું ભોજન</span><span class="font-medium text-amber-100/70">07:00 – 09:00</span></li>
                </ul>
            </div>

            {{-- Capacity --}}
            <div class="bg-amber-900/20 rounded-xl p-5 border border-amber-800/30">
                <h3 class="font-semibold text-gold mb-3 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    ક્ષમતા
                </h3>
                <ul class="space-y-2 text-sm text-amber-100/60">
                    <li class="flex justify-between"><span>એક સમયે</span><span class="font-medium text-amber-100/70">૨૦૦+ ભક્તો</span></li>
                    <li class="flex justify-between"><span>ઉત્સવ સમયે</span><span class="font-medium text-amber-100/70">૫૦૦+ ભક્તો</span></li>
                    <li class="flex justify-between"><span>વિશેષ પંગત</span><span class="font-medium text-amber-100/70">પૂર્વ-બુકિંગ ફરજિયાત</span></li>
                </ul>
            </div>

        </div>
    </div>

    {{-- Menu / Food Info --}}
    <div class="card-sacred p-6 sm:p-8 mb-8">
        <h2 class="text-xl font-bold text-gold mb-4">ભોજનની વિવિધતા</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🍛</p>
                <p class="font-semibold text-amber-100/70 text-sm">દાળ-ભાત-રોટલી</p>
                <p class="text-xs text-amber-100/40 mt-1">રોજનું સાત્ત્વિક ભોજન</p>
            </div>
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🍮</p>
                <p class="font-semibold text-amber-100/70 text-sm">પ્રસાદ / મીઠાઈ</p>
                <p class="text-xs text-amber-100/40 mt-1">પૂજા બાદ પ્રસાદ વિતરણ</p>
            </div>
            <div class="text-center p-4 bg-amber-900/20 rounded-xl border border-amber-800/30">
                <p class="text-2xl mb-2">🥣</p>
                <p class="font-semibold text-amber-100/70 text-sm">ઉત્સવ વિશેષ</p>
                <p class="text-xs text-amber-100/40 mt-1">તહેવારે વિશેષ થાળ</p>
            </div>
        </div>
    </div>

    {{-- Rules --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">ભોજનાલયના નિયમો</h2>
        <ul class="space-y-3">
            @foreach([
                'ભોજનાલયમાં પ્રવેશતા પહેલાં હાથ-પગ ધોવા.',
                'ભોજન સમયે મૌન અને શાંત વાતાવરણ જાળવવું.',
                'થાળીમાં જેટલું ખાવાનું હોય તેટલું જ લેવું — અન્નનો બગાડ ન કરો.',
                'ભોજન બાદ થાળી નિર્ધારિત જગ્યાએ મૂકી દેવી.',
                'ભોજન સમયે મોબાઈલ ફોન બંધ અથવા સાઈલન્ટ રાખવો.',
                'ભોજનાલયનો ઉપયોગ ફક્ત ટ્રસ્ટ-નોંધાયેલ યાત્રિકો અને ભક્તો માટે છે.',
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
