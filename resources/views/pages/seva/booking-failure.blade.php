@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    <div class="w-20 h-20 bg-red-950/40 border border-red-800/30 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </div>
    <h1 class="text-2xl font-bold text-red-300 mb-2">પેમેન્ટ નિષ્ફળ</h1>
    <p class="text-amber-100/50 mb-6">પેમેન્ટ રદ થયું છે અથવા નિષ્ફળ ગયું છે. કૃપા કરીને ફરી પ્રયાસ કરો.</p>
    <a href="{{ route('seva.index') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">ફરી પ્રયાસ કરો</a>
</div>
@endsection
