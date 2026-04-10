@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('dashboard.index') }}" class="hover:text-gold transition">ડેશબોર્ડ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">મારા દાન</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">મારા દાન</h1>
        <p class="mt-2 divine-subtext">તમારા બધા દાનની યાદી</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <div class="card-sacred overflow-hidden">

        @if($donations->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15 border-b border-amber-900/20">
                        <tr>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">તારીખ</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">રકમ</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">પ્રકાર</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">હેતુ</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">રસીદ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @foreach($donations as $donation)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-5 py-4 text-amber-100/60">
                                {{ $donation->created_at->format('d M Y') }}
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
                                        ડાઉનલોડ
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
                <p class="text-amber-100/30 mb-4">કોઈ દાન રેકોર્ડ નથી.</p>
                <a href="{{ route('donate') }}" class="inline-flex items-center gap-2 px-5 py-2.5 btn-divine text-sm font-semibold">
                    દાન કરો
                </a>
            </div>
        @endif

    </div>

</div>

@endsection
