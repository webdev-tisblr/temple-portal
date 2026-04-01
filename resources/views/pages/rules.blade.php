@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">મંદિરના નિયમો</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">મંદિરના નિયમો</h1>
        <p class="mt-2 divine-subtext">ભક્તો માટે આચાર-સંહિતા — કૃપા કરી પાળો</p>
    </div>
</section>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10">

        <p class="text-amber-100/60 mb-8 leading-relaxed">
            શ્રી પાતળિયા હનુમાનજી ધામ ની પવિત્રતા અને સૌ ભક્તોના સુખ-શાંતિ
            જળવાઈ રહે તે ઉદ્દેશ્‌થી ટ્રસ્ટ દ્વારા આ નિયમો ઘડ્‌યા છે.
            સૌ ભક્તો ને વિનંતી છે કે આ નિયમોનું પૂર્ણ પાલન કરે.
        </p>

        <ol class="space-y-6">

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">1</span>
                <div>
                    <p class="font-semibold text-amber-100/70">પગરખાં બહાર ઉતારો</p>
                    <p class="text-sm text-amber-100/40 mt-1">મંદિર પ્રવેશ-દ્વાર પહેલાં ચપ્પલ-જૂતા ઉતારવા ફરજિયાત છે. ચપ્પલ-સ્ટેન્ડ ઉ. available.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">2</span>
                <div>
                    <p class="font-semibold text-amber-100/70">સભ્ય વસ્ત્ર-સંહિતા (Dress Code)</p>
                    <p class="text-sm text-amber-100/40 mt-1">ભારતીય traditional / formal dress paheravo. Short, tight, revealing dress maa allowed nathi.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">3</span>
                <div>
                    <p class="font-semibold text-amber-100/70">ફોટો / Video — No Photography</p>
                    <p class="text-sm text-amber-100/40 mt-1">Garbha-Griha ma photography / videography strictly prohibited. Hall ma Trust permission levani.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">4</span>
                <div>
                    <p class="font-semibold text-amber-100/70">મૌન — Maintain Silence</p>
                    <p class="text-sm text-amber-100/40 mt-1">Aarti / Puja samay mobile silent rakhavo. Loud talking avoid karo. Bhajan ma bhav sathe sahbhagi thao.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">5</span>
                <div>
                    <p class="font-semibold text-amber-100/70">મોબાઈલ ફોન સાઈલન્ટ મોડ</p>
                    <p class="text-sm text-amber-100/40 mt-1">Mandir parisar ma mobile phone silent / vibrate mode ma rakhavo. Calls bahar j levo.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">6</span>
                <div>
                    <p class="font-semibold text-amber-100/70">સ્વચ્છ‌taa / Cleanliness</p>
                    <p class="text-sm text-amber-100/40 mt-1">Mandir parisar ma gandagi na karvo. Dustbin use karvo. Prasad wrapper dustbin ma j nakho.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">7</span>
                <div>
                    <p class="font-semibold text-amber-100/70">No Smoking / Alcohol</p>
                    <p class="text-sm text-amber-100/40 mt-1">Mandir parisar ma dhumrapaan (smoking), madiraPaan (alcohol) ane tamaku completely prohibited.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">8</span>
                <div>
                    <p class="font-semibold text-amber-100/70">Prasad / Chadhavo Guidelines</p>
                    <p class="text-sm text-amber-100/40 mt-1">Prasad / chadhavo faqat Trust-approved items j chadhavano. Non-veg items allowed nathi.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">9</span>
                <div>
                    <p class="font-semibold text-amber-100/70">કતાર પાળો — Queue Maintain Karo</p>
                    <p class="text-sm text-amber-100/40 mt-1">Darshan samay queue ma rahevu ane badha ne samaan darshan malshe. Queue cutting strictly prohibited.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">10</span>
                <div>
                    <p class="font-semibold text-amber-100/70">ઉત્સવ / તહેવાર ના વિશેષ નિયમો</p>
                    <p class="text-sm text-amber-100/40 mt-1">Festival / special puja divas ma Trust na anushasan ma rahevanu. Sevak / volunteer na nirdes mandatory.</p>
                </div>
            </li>

        </ol>

        <div class="mt-10 bg-amber-900/20 border border-amber-800/30 rounded-xl p-5">
            <p class="text-sm text-amber-100/50">
                નિ.yam paalan babat Trust ni final authority reheshe.
                Koi prashna mate <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold underline font-semibold transition">સ‌ymparak</a> karo.
            </p>
        </div>

    </div>
</div>

@endsection
