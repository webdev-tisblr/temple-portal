<x-filament-panels::page>
    {{-- Print rules live with the page rather than in app.css: only this
         screen is ever printed, and a trustees' meeting wants the tables and
         the calendar on paper without the panel chrome around them. --}}
    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header, .no-print { display: none !important; }
            .fi-main, .fi-page { padding: 0 !important; max-width: none !important; }
            .print-block { break-inside: avoid; }
            .print-only { display: block !important; }
            body { background: #fff !important; }
        }
        .print-only { display: none; }
    </style>

    @php
        $snapshot = $this->daySnapshot;
        $stats    = $this->donationStats;
        $slots    = $this->slotUtilisation;
        $nearing  = $this->nearingBookings;
        $calendar = $this->calendar;
    @endphp

    {{-- ── 1 · Day snapshot ─────────────────────────────────────────── --}}
    <x-filament::section class="print-block">
        <x-slot name="heading">A day at a glance</x-slot>
        <x-slot name="headerEnd">
            <input type="date" wire:model.live="snapshotDate"
                   class="no-print rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
        </x-slot>

        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <h3 class="mb-2 text-sm font-semibold">Seva bookings ({{ $snapshot['sevas']->count() }})</h3>
                @forelse ($snapshot['sevas'] as $b)
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 text-sm dark:border-gray-800">
                        <span>{{ $b->seva?->name_en ?: $b->seva?->name_gu }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ $b->slot_time === 'full_day' ? 'Whole day' : $b->slot_time }}
                            · {{ $b->devotee_name_for_seva ?: $b->devotee?->name }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No seva bookings on this date.</p>
                @endforelse
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold">Hall bookings ({{ $snapshot['halls']->count() }})</h3>
                @forelse ($snapshot['halls'] as $b)
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 text-sm dark:border-gray-800">
                        <span>{{ $b->hall?->getAttributes()['name'] ?? '—' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ $b->days_count > 1 ? $b->days_count.' days' : 'Single day' }} · {{ $b->contact_name }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hall bookings on this date.</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>

    {{-- ── 3 · Slot utilisation (same date as the snapshot) ─────────── --}}
    <x-filament::section class="print-block">
        <x-slot name="heading">How full each seva is</x-slot>
        <x-slot name="description">Booked against what is actually offered on {{ $snapshotDate }}.</x-slot>

        <div class="space-y-3">
            @foreach ($slots as $row)
                <div>
                    <div class="mb-1 flex justify-between text-sm">
                        <span>{{ $row['seva'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            @if ($row['offered'])
                                {{ $row['taken'] }} / {{ $row['total'] }} slots · {{ $row['pct'] }}%
                            @else
                                {{ $row['reason'] }}
                            @endif
                        </span>
                    </div>
                    @if ($row['offered'])
                        <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-2 rounded-full {{ $row['pct'] >= 100 ? 'bg-danger-500' : 'bg-primary-500' }}"
                                 style="width: {{ max(2, $row['pct']) }}%"></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- ── 2 · Donations over a range ───────────────────────────────── --}}
    <x-filament::section class="print-block">
        <x-slot name="heading">Donations</x-slot>
        <x-slot name="headerEnd">
            <div class="no-print flex items-center gap-2 text-sm">
                <input type="date" wire:model.live="rangeStart"
                       class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <span class="text-gray-400">to</span>
                <input type="date" wire:model.live="rangeEnd"
                       class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
            </div>
        </x-slot>

        <div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-5">
            @foreach ([
                ['Total', inr_money($stats['total'])],
                ['Donations', number_format($stats['count'])],
                ['Average', inr_money($stats['average'])],
                ['Smallest', inr_money($stats['min'])],
                ['Largest', inr_money($stats['max'])],
            ] as [$label, $value])
                <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-0.5 text-lg font-semibold">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{-- Bars in plain markup rather than a JS chart: it prints, it needs
             no library, and the series is at most a few hundred days. --}}
        <div class="flex h-40 items-end gap-px overflow-x-auto border-b border-gray-200 dark:border-gray-700">
            @foreach ($stats['series'] as $day => $amount)
                <div class="group relative flex-1" style="min-width: 3px;"
                     title="{{ \Illuminate\Support\Carbon::parse($day)->format('d M Y') }} — {{ inr_money($amount) }}">
                    <div class="w-full rounded-t bg-primary-500/80 hover:bg-primary-600"
                         style="height: {{ $stats['peak'] > 0 ? max(1, (int) round($amount / $stats['peak'] * 150)) : 1 }}px"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>{{ \Illuminate\Support\Carbon::parse($rangeStart)->format('d M Y') }}</span>
            <span>{{ $stats['days'] }} days · peak {{ inr_money($stats['peak']) }}</span>
            <span>{{ \Illuminate\Support\Carbon::parse($rangeEnd)->format('d M Y') }}</span>
        </div>
    </x-filament::section>

    {{-- ── 4 · Nearing bookings ─────────────────────────────────────── --}}
    <x-filament::section class="print-block">
        <x-slot name="heading">Coming up</x-slot>
        <x-slot name="description">Next {{ $nearingDays }} days, soonest first.</x-slot>

        @forelse ($nearing as $b)
            <div class="flex flex-wrap justify-between gap-2 border-b border-gray-100 py-2 text-sm dark:border-gray-800">
                <span class="font-medium">{{ \Illuminate\Support\Carbon::parse($b['date'])->format('D d M') }}</span>
                <span>
                    <span @class([
                        'rounded px-1.5 py-0.5 text-xs',
                        'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' => $b['kind'] === 'Seva',
                        'bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300' => $b['kind'] === 'Hall',
                    ])>{{ $b['kind'] }}</span>
                    {{ $b['what'] }}
                </span>
                <span class="text-gray-500 dark:text-gray-400">{{ $b['detail'] }} · {{ $b['who'] }}</span>
                <span class="font-medium">{{ inr_money($b['amount']) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing booked in the next {{ $nearingDays }} days.</p>
        @endforelse
    </x-filament::section>

    {{-- ── 5 · Calendar + printable list ────────────────────────────── --}}
    <x-filament::section class="print-block">
        <x-slot name="heading">{{ $calendar['label'] }}</x-slot>
        <x-slot name="headerEnd">
            <div class="no-print flex items-center gap-2">
                <x-filament::button size="xs" color="gray" wire:click="previousMonth" icon="heroicon-m-chevron-left" />
                <x-filament::button size="xs" color="gray" wire:click="thisMonth">Today</x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="nextMonth" icon="heroicon-m-chevron-right" />
                <x-filament::button size="xs" color="primary" icon="heroicon-o-printer" onclick="window.print()">
                    Print
                </x-filament::button>
            </div>
        </x-slot>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg bg-gray-200 text-center text-xs dark:bg-gray-700">
            @foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
                <div class="bg-gray-50 py-1.5 font-semibold dark:bg-gray-800">{{ $dow }}</div>
            @endforeach

            @foreach ($calendar['weeks'] as $week)
                @foreach ($week as $cell)
                    <div @class([
                        'min-h-[64px] bg-white p-1.5 text-left dark:bg-gray-900',
                        'opacity-40' => ! $cell['inMonth'],
                        'ring-2 ring-inset ring-primary-500' => $cell['isToday'],
                    ])>
                        <div class="font-medium">{{ $cell['day'] }}</div>
                        @if ($cell['seva'])
                            <div class="mt-0.5 truncate text-primary-600 dark:text-primary-400">{{ $cell['seva'] }} seva</div>
                        @endif
                        @if ($cell['hall'])
                            <div class="truncate text-warning-600 dark:text-warning-400">hall</div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- The list is what actually goes on paper: a grid of counts is
             useless at a meeting, a list of who booked what is not. --}}
        <div class="mt-6">
            <h3 class="mb-2 text-sm font-semibold">
                All bookings · {{ \Illuminate\Support\Carbon::parse($rangeStart)->format('d M Y') }}
                – {{ \Illuminate\Support\Carbon::parse($rangeEnd)->format('d M Y') }}
                <span class="font-normal text-gray-500 dark:text-gray-400">({{ $calendar['printList']->count() }})</span>
            </h3>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="py-2 pe-3">Date</th>
                            <th class="py-2 pe-3">Type</th>
                            <th class="py-2 pe-3">What</th>
                            <th class="py-2 pe-3">Detail</th>
                            <th class="py-2 pe-3">Who</th>
                            <th class="py-2 pe-3">Phone</th>
                            <th class="py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($calendar['printList'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-1.5 pe-3 whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="py-1.5 pe-3">{{ $row['kind'] }}</td>
                                <td class="py-1.5 pe-3">{{ $row['what'] }}</td>
                                <td class="py-1.5 pe-3">{{ $row['detail'] }}</td>
                                <td class="py-1.5 pe-3">{{ $row['who'] }}</td>
                                <td class="py-1.5 pe-3 whitespace-nowrap">{{ $row['phone'] }}</td>
                                <td class="py-1.5 text-right whitespace-nowrap">{{ inr_money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-3 text-gray-500 dark:text-gray-400">No bookings in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
