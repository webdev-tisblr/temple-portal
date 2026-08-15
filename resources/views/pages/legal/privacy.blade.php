@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => 'Privacy Policy']]"
    title="Privacy Policy"
    subtitle="How we collect, use, and protect your information" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10 space-y-8 text-amber-100/70 leading-relaxed">

        <p class="text-amber-100/50 text-sm">Last updated: {{ $updated }}</p>

        <p>
            This Privacy Policy explains how <strong>{{ $trustName }}</strong> ("we", "us",
            "the Trust") handles your information when you use our mobile application
            (શ્રી પાતાળિયા હનુમાનજી) and website at
            <a href="https://patadiyahanumanji.com" class="text-amber-500 underline">patadiyahanumanji.com</a>.
            By using the app or website, you agree to this policy.
        </p>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">1. Information We Collect</h2>
            <ul class="list-disc pl-6 space-y-2">
                <li><strong>Account information:</strong> your mobile phone number (used to sign in via OTP), and the name you provide.</li>
                <li><strong>Profile information you choose to add:</strong> email address, postal address, city, state, PIN code, date of birth, profile photo, and preferred language.</li>
                <li><strong>PAN (tax ID):</strong> only if you choose to provide it, so we can issue an 80G donation tax receipt. It is stored encrypted.</li>
                <li><strong>Transaction information:</strong> records of your donations, seva bookings, store orders, and hall bookings, including amounts and dates.</li>
                <li><strong>Device & notification data:</strong> a push notification token (via Firebase Cloud Messaging) so we can send you temple updates, and basic device platform (Android/iOS).</li>
                <li><strong>Photos:</strong> if you set a profile picture or save a darshan/greeting card, we access your camera or photo library only for that action.</li>
            </ul>
            <p class="mt-3"><strong>We do not collect or store your payment card / UPI details.</strong> All payments are processed directly by our payment gateway (Razorpay).</p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">2. How We Use Your Information</h2>
            <ul class="list-disc pl-6 space-y-2">
                <li>To sign you in and maintain your account.</li>
                <li>To process donations, bookings, and orders, and to issue receipts and invoices.</li>
                <li>To send transactional and temple notifications (push, SMS, or WhatsApp) that you have opted into.</li>
                <li>To provide customer support and respond to your enquiries.</li>
                <li>To comply with legal, tax, and accounting obligations.</li>
            </ul>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">3. Third-Party Services</h2>
            <p>We share the minimum data necessary with trusted service providers who process it on our behalf:</p>
            <ul class="list-disc pl-6 space-y-2 mt-2">
                <li><strong>Razorpay</strong> — payment processing.</li>
                <li><strong>Google Firebase (Cloud Messaging)</strong> — push notifications.</li>
                <li><strong>Cloudflare R2</strong> — secure storage of images, receipts, and invoices.</li>
                <li><strong>WhatsApp Business API &amp; SMS provider</strong> — delivery of OTP and notifications you opted into.</li>
                <li>
                    <strong>Google Analytics</strong> — visitor statistics for this website. It sets
                    cookies to count visits and see which pages are used, so we can improve the site.
                    It records general information such as the pages you view, roughly where in the
                    world you are, and the type of device and browser you use. <strong>It is never
                    given your name, phone number, email address, donation amounts, or anything else
                    you enter on this site.</strong> You can switch it off with any browser setting or
                    extension that blocks analytics cookies, and the site will keep working normally.
                </li>
            </ul>
            <p class="mt-3"><strong>We never sell your personal information.</strong></p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">4. Data Retention</h2>
            <p>
                We keep your account information for as long as your account is active. Financial
                records (donations, the 80G tax receipts issued against them, and paid bookings/orders)
                are retained for the period required by Indian tax and accounting law, even after you
                delete your account — but they are anonymised so they are no longer linked to your
                personal identity.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">5. Your Rights & Account Deletion</h2>
            <p>
                You can view and edit your profile information at any time inside the app. You may
                delete your account directly from the app (Profile → Delete Account) or by visiting
                <a href="{{ route('legal.account-deletion') }}" class="text-amber-500 underline">our account deletion page</a>.
                On deletion we erase your personal data and anonymise the financial records we are
                legally required to keep.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">6. Security</h2>
            <p>
                We use HTTPS for all data in transit, encrypt sensitive fields such as your PAN at
                rest, and restrict access to personal data. No method of transmission or storage is
                100% secure, but we work to protect your information.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">7. Children's Privacy</h2>
            <p>
                The app is intended for a general audience and is not directed at children under 13.
                We do not knowingly collect personal information from children under 13.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">8. Changes to This Policy</h2>
            <p>
                We may update this policy from time to time. Material changes will be reflected on
                this page with a new "Last updated" date.
            </p>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gold mb-3">9. Contact Us</h2>
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
