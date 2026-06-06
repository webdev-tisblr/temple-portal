@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => 'Terms of Service']]"
    title="Terms of Service"
    subtitle="The terms that govern your use of our app and services" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10 space-y-8 text-amber-100/70 leading-relaxed">

        <p class="text-amber-100/50 text-sm">Last updated: {{ $updated }}</p>

        <p>
            These Terms of Service ("Terms") govern your use of the mobile application and website
            operated by <strong>{{ $trustName }}</strong>. By creating an account or using our
            services, you agree to these Terms.
        </p>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">1. Eligibility & Accounts</h2>
            <p>
                You must be at least 18 years old, or use the app under the supervision of a parent or
                guardian, to make payments. You are responsible for keeping your account and the device
                you sign in on secure. Sign-in is via a one-time password (OTP) sent to your mobile number.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">2. Donations, Seva, Orders & Bookings</h2>
            <ul class="list-disc pl-6 space-y-2">
                <li>Donations made through the app are voluntary contributions to the Trust.</li>
                <li>Seva bookings, store orders, and hall bookings are subject to availability and to confirmation by the Trust.</li>
                <li>Prices are shown in Indian Rupees (₹) and include applicable charges as displayed at checkout.</li>
                <li>Tax-exemption (80G) receipts are issued where applicable, subject to you providing accurate details.</li>
            </ul>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">3. Payments</h2>
            <p>
                Payments are processed by our third-party gateway (Razorpay). By making a payment you
                also agree to the gateway's terms. We do not store your card or UPI credentials.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">4. Refunds & Cancellations</h2>
            <p>
                Refund and cancellation terms are described in our
                <a href="{{ route('legal.refund') }}" class="text-amber-500 underline">Refund &amp; Cancellation Policy</a>.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">5. Acceptable Use</h2>
            <p>You agree not to misuse the app, including by attempting to disrupt the service, access
            other users' data, submit false information, or use the service for any unlawful purpose.</p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">6. Content & Intellectual Property</h2>
            <p>
                All temple content — images, text, logos, and media — is the property of the Trust or
                its licensors and may not be reproduced without permission. Content you submit (such as
                a profile photo) remains yours; you grant us a limited licence to display it within the app.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">7. Disclaimers & Limitation of Liability</h2>
            <p>
                The service is provided "as is". To the extent permitted by law, the Trust is not liable
                for indirect or consequential losses arising from your use of the app. Nothing in these
                Terms limits liability that cannot be limited under applicable law.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">8. Account Deletion</h2>
            <p>
                You may delete your account at any time from within the app (Profile → Delete Account)
                or via our <a href="{{ route('legal.account-deletion') }}" class="text-amber-500 underline">account deletion page</a>.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">9. Governing Law</h2>
            <p>These Terms are governed by the laws of India, with jurisdiction in the courts of Gujarat.</p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">10. Contact</h2>
            <p>
                {{ $trustName }}<br>
                @if($address){{ $address }}<br>@endif
                Email: <a href="mailto:{{ $email }}" class="text-amber-500 underline">{{ $email }}</a>
                @if($phone)<br>Phone: <a href="tel:{{ $phone }}" class="text-amber-500 underline">{{ $phone }}</a>@endif
            </p>
        </div>

    </div>
</div>

@endsection
