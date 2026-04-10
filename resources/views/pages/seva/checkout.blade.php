@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    <div class="animate-pulse mb-6">
        <div class="w-16 h-16 bg-amber-900/30 border border-amber-700/30 rounded-full flex items-center justify-center mx-auto">
            <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
    </div>
    <h1 class="text-xl font-semibold text-amber-100/70 mb-2">પેમેન્ટ પ્રોસેસ થઈ રહ્યું છે...</h1>
    <p class="text-amber-100/40">Razorpay ચેકઆઉટ ખુલી રહ્યું છે. કૃપા કરીને રાહ જુઓ.</p>
    <p class="text-sm text-amber-100/30 mt-4">{{ $description }} — ₹{{ number_format($amount / 100) }}</p>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var options = {
        key: "{{ $razorpayKeyId }}",
        amount: {{ $amount }},
        currency: "{{ $currency }}",
        name: "શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ",
        description: "{{ $description }}",
        order_id: "{{ $orderId }}",
        prefill: {
            name: "{{ $devoteeName }}",
            contact: "{{ $devoteePhone }}",
            email: "{{ $devoteeEmail }}"
        },
        theme: { color: "#e8c36a" },
        handler: function(response) {
            window.location.href = "{{ $successUrl ?? route('seva.booking.success') }}?payment_id=" + response.razorpay_payment_id + "&order_id=" + response.razorpay_order_id + "&signature=" + response.razorpay_signature;
        },
        modal: {
            ondismiss: function() {
                window.location.href = "{{ $failureUrl ?? route('seva.booking.failure') }}";
            }
        }
    };
    var rzp = new Razorpay(options);
    rzp.open();
});
</script>
@endsection
