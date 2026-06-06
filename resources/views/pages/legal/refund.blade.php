@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => 'Refund & Cancellation Policy']]"
    title="Refund & Cancellation Policy"
    subtitle="Refunds and cancellations for donations, seva, orders, and bookings" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10 space-y-8 text-amber-100/70 leading-relaxed">

        <p class="text-amber-100/50 text-sm">Last updated: {{ $updated }}</p>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Donations</h2>
            <p>
                Donations are voluntary contributions to {{ $trustName }} and are generally
                <strong>non-refundable</strong>. If a donation was made in error or charged more than
                once due to a technical issue, please contact us within <strong>7 days</strong> with the
                transaction details and we will review and, where appropriate, refund the duplicate or
                erroneous amount to the original payment method.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Seva Bookings</h2>
            <p>
                If a seva cannot be performed on the booked date due to circumstances on the Trust's side,
                you will be offered an alternative date or a full refund. Cancellation requests made by you
                are considered on a case-by-case basis depending on how close the booking is to the seva date.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Store Orders</h2>
            <p>
                Prasad and physical items may be eligible for replacement or refund if they arrive damaged
                or incorrect. Please contact us within <strong>48 hours</strong> of delivery with photos.
                Perishable items (e.g. prasad) cannot be returned once delivered unless defective.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Hall Bookings</h2>
            <p>
                Hall booking cancellations are subject to the terms shown at the time of booking.
                Where a refund applies, any non-refundable advance or administrative charge will be
                deducted before the balance is returned.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">How Refunds Are Processed</h2>
            <p>
                Approved refunds are returned to the original payment method via our payment gateway
                (Razorpay) and typically appear within <strong>5–7 business days</strong>, depending on
                your bank.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Contact for Refunds</h2>
            <p>
                To request a refund or cancellation, contact us with your transaction ID:<br>
                Email: <a href="mailto:{{ $email }}" class="text-amber-500 underline">{{ $email }}</a>
                @if($phone)<br>Phone: <a href="tel:{{ $phone }}" class="text-amber-500 underline">{{ $phone }}</a>@endif
            </p>
        </div>

    </div>
</div>

@endsection
