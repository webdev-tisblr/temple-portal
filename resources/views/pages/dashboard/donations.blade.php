@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.my_donations')],
    ]"
    title="{{ __('dashboard.my_donations') }}"
    subtitle="{{ __('dashboard.my_donations_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <div class="card-sacred overflow-hidden">

        @if($donations->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15 border-b border-amber-900/20">
                        <tr>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600">{{ __('common.date') }}</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600">{{ __('common.amount') }}</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600">{{ __('donation.type_label') }}</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600">{{ __('dashboard.purpose') }}</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600">{{ __('dashboard.receipt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @foreach($donations as $donation)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-5 py-4 text-amber-100/60">
                                {{ $donation->created_at->format('d/m/Y') }}
                            </td>
                            <td class="px-5 py-4 font-bold text-gold">₹{{ number_format((float) $donation->amount) }}</td>
                            <td class="px-5 py-4">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-900/30 text-amber-400">
                                    {{ ucfirst($donation->getRawOriginal('donation_type')) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-amber-100/50 max-w-xs truncate">
                                {{ $donation->purpose ?? '—' }}
                            </td>
                            <td class="px-5 py-4">
                                @if($donation->receipt && $donation->receipt->pdf_path)
                                    <a href="{{ route('dashboard.receipts.download', $donation->receipt) }}"
                                       class="inline-flex items-center gap-1 text-xs text-amber-500 font-semibold hover:text-gold transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        {{ __('dashboard.download') }}
                                    </a>
                                @else
                                    <span class="text-amber-100/20 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-amber-900/20">
                {{ $donations->links() }}
            </div>

        @else
            <div class="text-center py-16">
                <svg class="w-12 h-12 text-amber-800/30 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-amber-100/30 mb-4">{{ __('dashboard.no_donation_records') }}</p>
                <a href="{{ route('donate') }}" class="inline-flex items-center gap-2 px-5 py-2.5 btn-divine text-sm font-semibold">
                    {{ __('nav.donate') }}
                </a>
            </div>
        @endif

    </div>

</div>

@endsection
