@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    @if($verified && $donation)
        <div class="w-20 h-20 bg-emerald-950/50 border border-emerald-800/40 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-emerald-300 mb-2">આભાર! દાન સફળ થયું.</h1>
        <p class="text-amber-100/50 mb-4">તમારું દાન સફળતાપૂર્વક પ્રાપ્ત થયું છે.</p>

        <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-4 text-left mt-6 text-sm">
            <div class="flex justify-between py-2 border-b border-amber-900/20">
                <span class="text-amber-100/40">રકમ</span>
                <span class="font-bold text-gold">₹{{ number_format((float) $donation->amount) }}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-amber-900/20">
                <span class="text-amber-100/40">પ્રકાર</span>
                <span class="font-medium text-amber-100/60">{{ ucfirst($donation->donation_type->value) }}</span>
            </div>
            <div class="flex justify-between py-2">
                <span class="text-amber-100/40">80G રસીદ</span>
                <span class="font-medium">
                    @if($donation->receipt_generated && $donation->receipt)
                        <a href="#" class="text-amber-500 hover:text-gold underline transition">ડાઉનલોડ</a>
                    @else
                        <span class="text-amber-100/30">ટૂંક સમયમાં ઉપલબ્ધ</span>
                    @endif
                </span>
            </div>
        </div>

        <div class="mt-8 space-x-3">
            <a href="{{ route('donate') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">વધુ દાન કરો</a>
            <a href="{{ route('dashboard.index') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">ડેશબોર્ડ</a>
        </div>
    @else
        <div class="w-20 h-20 bg-amber-900/30 border border-amber-700/30 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-amber-300 mb-2">ચકાસણી બાકી</h1>
        <p class="text-amber-100/50">પેમેન્ટ ચકાસણી થઈ શકી નથી. જો સફળ થયું હોય, તો ડેશબોર્ડમાં દેખાશે.</p>
        <a href="{{ route('dashboard.index') }}" class="mt-6 inline-flex items-center px-6 py-2.5 btn-divine">ડેશબોર્ડ જુઓ</a>
    @endif
</div>
@endsection
