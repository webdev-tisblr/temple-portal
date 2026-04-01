@extends('layouts.app')

@section('content')

{{-- Page Header --}}
<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        {{-- Breadcrumbs --}}
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">પૂજારી</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">પૂજારી</h1>
        <p class="mt-2 divine-subtext">શ્રી પાતળિયા હનુમાનજી મંદિર — પૂજારી અને સેવક</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="max-w-3xl mx-auto text-center mb-12">
        <p class="text-amber-100/60 leading-relaxed text-lg">
            શ્રી પાતળિયા હનુમાનજી મંદિર ના સ્નાતક-સ્તર ના ધર્મ-નિષ્ઠ પૂજારીઓ પ્રતિ દિન ભગવાન
            હનુમાનજી ની પૂજા-અર્ચના, અભિષેક, ભોગ-ચઢાવ અને આરતી ભાવ-પૂર્ણ રીતે સંપન્ન કરે છે.
        </p>
    </div>

    {{-- Priest Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">

        {{-- Priest 1 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-700/40 to-amber-600/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">પં. ગોવર્ધનભાઈ ભટ્ટ</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">મુખ્ય પૂજારી (Head Priest)</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>અનુભવ: ૨૫+ વર્ષ</p>
                    <p>વ્યખ્યયાર્ પ્રશિક્ષણ: વૈષ્ણવ પરંપરા</p>
                </div>
            </div>
        </div>

        {{-- Priest 2 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-500/40 to-amber-400/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">પં. દિવ્યકાન્ત ઉપાધ્યાય</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">સહ-પૂજારી (Associate Priest)</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>અનુભવ: ૧૫+ વર્ષ</p>
                    <p>વ્યખ્યયાર્ પ્રશિક્ષણ: શૈવ–વૈષ્ણવ</p>
                </div>
            </div>
        </div>

        {{-- Priest 3 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-600/40 to-amber-500/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">પં. સુરેશ ત્રિવેદી</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">સેવક-પૂજારી (Sevak Priest)</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>અનુભવ: ૧૦+ વર્ષ</p>
                    <p>વ્યખ્યયાર્ પ્રશિક્ષણ: સ્‍વામિ-સ્‍ત્રોત</p>
                </div>
            </div>
        </div>

    </div>

    {{-- Daily Duties Info --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">દૈનિક સેવા ક્રમ</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-amber-100/60">
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                <span>સવારે મંગળ-ઉઠ્ઠાવ, ભોગ-ચઢાવ, અભિષેક</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                <span>સવારે આરતી (Mangala Aarti)</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                <span>ભક્ત-સેવા, ચઢાવ-ગ્રહણ, સૂત્ર-પૂજા</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                <span>સાંજ-ભોગ, દ્વીપ-ઉત્સ્‍વ, સાંધ્ય-આરતી</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">5</span>
                <span>ઉત્સવ-પ્રસઙ્ગ: ભજન-કીર્તન, ઉત્સ્‍વ-પૂજા</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">6</span>
                <span>રાત્રી-શૃઙ્gar, ભગવાન-સ્‍ત્રોત, શ્‍યાન-ભોગ</span>
            </div>
        </div>
    </div>

</div>

@endsection
