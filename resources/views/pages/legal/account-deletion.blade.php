@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => 'Delete Your Account']]"
    title="Delete Your Account"
    subtitle="Remove your account and personal data from the temple app" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10 space-y-8 text-amber-100/70 leading-relaxed">

        <p>
            You can permanently delete your <strong>{{ $trustName }}</strong> account and personal
            data at any time. There are two ways to do this.
        </p>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Option 1 — Delete from inside the app (fastest)</h2>
            <ol class="list-decimal pl-6 space-y-2">
                <li>Open the શ્રી પાતાળિયા હનુમાનજી app and sign in.</li>
                <li>Go to <strong>More → Profile</strong>.</li>
                <li>Tap <strong>Delete Account</strong> and confirm.</li>
            </ol>
            <p class="mt-3">Your account and personal data are removed immediately.</p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Option 2 — Request by email</h2>
            <p>
                If you can no longer access the app, email us from the address linked to your account (or
                include your registered mobile number) and we will delete your account within
                <strong>7 days</strong>:
            </p>
            <p class="mt-2">
                Email: <a href="mailto:{{ $email }}?subject=Account%20Deletion%20Request" class="text-amber-500 underline">{{ $email }}</a>
                @if($phone)<br>Phone: <a href="tel:{{ $phone }}" class="text-amber-500 underline">{{ $phone }}</a>@endif
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">What is deleted</h2>
            <ul class="list-disc pl-6 space-y-2">
                <li>Your name, email, postal address, date of birth, and PAN.</li>
                <li>Your profile photo.</li>
                <li>Your push-notification tokens (you stop receiving notifications).</li>
                <li>Your sign-in sessions — your phone number is released so the account can no longer be accessed.</li>
            </ul>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">What is retained, and why</h2>
            <p>
                Financial records — your donations, the 80G tax receipts issued against them, and any
                paid seva, store, or hall transactions — are kept for the period required by Indian tax
                and accounting law. After deletion these records are <strong>anonymised</strong> so they
                are no longer linked to your personal identity. They are retained only to meet legal and
                audit obligations and are not used to contact or identify you.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">Questions?</h2>
            <p>
                See our <a href="{{ route('legal.privacy') }}" class="text-amber-500 underline">Privacy Policy</a>
                or contact us at
                <a href="mailto:{{ $email }}" class="text-amber-500 underline">{{ $email }}</a>.
            </p>
        </div>

    </div>
</div>

@endsection
