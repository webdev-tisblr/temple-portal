@extends('layouts.app')

@section('content')

{{-- Page Header --}}
<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        {{-- Breadcrumbs --}}
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">ટ્રસ્ટીઓ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">ટ્રસ્ટીઓ</h1>
        <p class="mt-2 divine-subtext">શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ — ટ્રસ્ટ સભ્યો</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="max-w-3xl mx-auto text-center mb-12">
        <p class="text-amber-100/60 leading-relaxed text-lg">
            શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ, ગાંધીધામ–કચ્છ ના ટ્રસ્ટીઓ ભક્તિ, સેવા અને સમર્પણ ભાવ સાથે
            મંદિર સ્થળ, ધાર્મિક ઉત્સવો અને સામાજિક સેવા પ્રવૃત્તિઓ ચલાવે છે.
            નીચે આ ટ્રસ્ટ ના સ્થાપક અને વર્તમાન ટ્રસ્ટ સભ્યોનો પરિચય આપ્યો છે.
        </p>
    </div>

    {{-- Trustee Cards Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">

        {{-- Trustee 1 --}}
        <div class="card-sacred p-6 text-center">
            <div class="w-20 h-20 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                <svg class="w-10 h-10 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gold">શ્રી રામભાઈ પટેલ</h3>
            <p class="text-amber-500 font-medium text-sm mt-1">પ્રમુખ (Chairman)</p>
            <p class="text-amber-100/40 text-sm mt-2">ગાંધીધામ, કચ્છ</p>
        </div>

        {{-- Trustee 2 --}}
        <div class="card-sacred p-6 text-center">
            <div class="w-20 h-20 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                <svg class="w-10 h-10 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gold">શ્રી ભાવેશભાઈ શાહ</h3>
            <p class="text-amber-500 font-medium text-sm mt-1">સચિવ (Secretary)</p>
            <p class="text-amber-100/40 text-sm mt-2">ગાંધીધામ, કચ્છ</p>
        </div>

        {{-- Trustee 3 --}}
        <div class="card-sacred p-6 text-center">
            <div class="w-20 h-20 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                <svg class="w-10 h-10 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gold">શ્રી નટવરભાઈ ઠાકર</h3>
            <p class="text-amber-500 font-medium text-sm mt-1">કોષાધ્યક્ષ (Treasurer)</p>
            <p class="text-amber-100/40 text-sm mt-2">ગાંધીધામ, કચ્છ</p>
        </div>

        {{-- Trustee 4 --}}
        <div class="card-sacred p-6 text-center">
            <div class="w-20 h-20 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                <svg class="w-10 h-10 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gold">શ્રી જિતેન્દ્રભાઈ દવે</h3>
            <p class="text-amber-500 font-medium text-sm mt-1">ટ્રસ્ટી (Trustee)</p>
            <p class="text-amber-100/40 text-sm mt-2">ગાંધીધામ, કચ્છ</p>
        </div>

    </div>

    {{-- Contact Info --}}
    <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-6 text-center">
        <p class="text-amber-100/60">
            ટ્રસ્ટ સંબંધિત કોઈ જાણકારી માટે
            <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold font-semibold underline transition">સંપર્ક</a>
            પૃષ્ઠ ની મુલાકાત લો.
        </p>
    </div>

</div>

@endsection
