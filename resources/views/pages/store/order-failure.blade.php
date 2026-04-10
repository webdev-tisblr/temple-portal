@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    <div class="w-20 h-20 bg-red-950/40 border border-red-800/30 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
    </div>

    <h1 class="text-2xl font-bold text-red-300 mb-2">પેમેન્ટ નિષ્ફળ</h1>
    <p class="text-amber-100/50 mb-2">તમારું પેમેન્ટ પ્રક્રિયા પૂર્ણ થઈ શકી નથી.</p>
    <p class="text-amber-100/30 text-sm">કૃપા કરીને ફરી પ્રયાસ કરો. જો સમસ્યા ચાલુ રહે, તો અમારો સંપર્ક કરો.</p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ route('store.cart') }}" class="inline-flex items-center gap-2 px-6 py-2.5 btn-divine">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            કાર્ટ પર પાછા જાઓ
        </a>
        <a href="{{ route('home') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">મુખ્ય પૃષ્ઠ</a>
    </div>
</div>
@endsection
