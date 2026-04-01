@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('dashboard.index') }}" class="hover:text-gold transition">Dashboard</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">80G રસીદો</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">80G રસીદો</h1>
        <p class="mt-2 divine-subtext">Income tax 80G exemption certificates</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    {{-- Info Banner --}}
    <div class="mb-6 flex items-start gap-3 px-5 py-4 bg-blue-950/30 border border-blue-800/30 rounded-xl text-blue-300">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-sm">
            80G receipts income tax purposes mate valid chhe. PAN number update karavanu yaad rakhsho.
            <a href="{{ route('dashboard.profile') }}" class="underline font-semibold hover:text-blue-200 transition">પ્રોફાઇલ અપડેટ કરો.</a>
        </p>
    </div>

    <div class="card-sacred overflow-hidden">

        @if($receipts->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15 border-b border-amber-900/20">
                        <tr>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Receipt No.</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Daan Tarikh</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Rakam</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Financial Year</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">ડાઉનલોડ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @foreach($receipts as $receipt)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-5 py-4">
                                <span class="font-mono text-sm font-semibold text-amber-100/70">{{ $receipt->receipt_number }}</span>
                            </td>
                            <td class="px-5 py-4 text-amber-100/60">
                                {{ optional($receipt->donation_date)->format('d M Y') ?? optional($receipt->created_at)->format('d M Y') }}
                            </td>
                            <td class="px-5 py-4 font-bold text-gold">
                                ₹{{ number_format($receipt->amount) }}
                            </td>
                            <td class="px-5 py-4">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-950/40 text-blue-400">
                                    {{ $receipt->financial_year ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <a href="{{ route('dashboard.receipts.download', $receipt) }}"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 btn-divine text-xs font-semibold rounded-lg">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    PDF ડાઉનલોડ
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-amber-900/20">
                {{ $receipts->links() }}
            </div>

        @else
            <div class="text-center py-16">
                <svg class="w-12 h-12 text-amber-800/30 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p class="text-amber-100/30 mb-4">Tamari koi 80G receipts available nathi.</p>
                <p class="text-xs text-amber-100/20">Daan karyaa baad 80G certificate available thay chhe.</p>
            </div>
        @endif

    </div>

</div>

@endsection
