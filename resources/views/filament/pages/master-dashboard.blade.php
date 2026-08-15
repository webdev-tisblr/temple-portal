<x-filament-panels::page>
    @php
        $snapshot = $this->daySnapshot;
        $stats    = $this->donationStats;
        $util     = $this->slotUtilisation;
        $nearing  = $this->nearingBookings;
        $calendar = $this->calendar;
    @endphp

    {{-- ── Row 1 · the day, and what is coming ──────────────────────────
         Two-thirds / one-third: the day snapshot carries two columns of
         detail, the "coming up" list is one line per booking. --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">A day at a glance</x-slot>
            <x-slot name="headerEnd">
                <input type="date" wire:model.live="snapshotDate"
                       class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
            </x-slot>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach ([
                    ['Seva bookings', $snapshot['sevas'], 'seva'],
                    ['Hall bookings', $snapshot['halls'], 'hall'],
                ] as [$label, $items, $type])
                    <div>
                        <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold">
                            {{ $label }}
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ $items->count() }}
                            </span>
                        </h3>

                        @forelse ($items as $b)
                            <div class="border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-800">
                                <div class="font-medium">
                                    {{ $type === 'seva'
                                        ? ($b->seva?->name_en ?: $b->seva?->name_gu)
                                        : ($b->hall?->getAttributes()['name'] ?? '—') }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $type === 'seva'
                                        ? ($b->slot_time === 'full_day' ? 'Whole day' : $b->slot_time)
                                        : ($b->days_count > 1 ? $b->days_count.' days' : 'Single day') }}
                                    ·
                                    {{ $type === 'seva'
                                        ? ($b->devotee_name_for_seva ?: $b->devotee?->name)
                                        : $b->contact_name }}
                                </div>
                            </div>
                        @empty
                            <p class="py-2 text-sm text-gray-400">Nothing booked.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Coming up</x-slot>
            <x-slot name="description">Next {{ $nearingDays }} days</x-slot>

            <div class="max-h-80 space-y-2 overflow-y-auto">
                @forelse ($nearing as $b)
                    <div class="flex items-start justify-between gap-2 border-b border-gray-100 pb-2 text-sm last:border-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $b['what'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $b['detail'] }} · {{ $b['who'] }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-xs font-semibold">{{ \Illuminate\Support\Carbon::parse($b['date'])->format('d M') }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ inr_money($b['amount']) }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Nothing in the next {{ $nearingDays }} days.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    {{-- ── Row 2 · donations ────────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Donations</x-slot>
        <x-slot name="headerEnd">
            <div class="flex items-center gap-2 text-sm">
                <input type="date" wire:model.live="rangeStart"
                       class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
                <span class="text-gray-400">to</span>
                <input type="date" wire:model.live="rangeEnd"
                       class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
            </div>
        </x-slot>

        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([
                ['Total', inr_money($stats['total'])],
                ['Donations', number_format($stats['count'])],
                ['Average', inr_money($stats['average'])],
                ['Smallest', inr_money($stats['min'])],
                ['Largest', inr_money($stats['max'])],
            ] as [$label, $value])
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/50">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-0.5 truncate text-lg font-semibold">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{-- Bars in plain markup rather than a JS chart: no library, and the
             series is at most a few hundred days. --}}
        <div class="flex h-40 items-end gap-px overflow-x-auto border-b border-gray-200 dark:border-gray-700">
            @foreach ($stats['series'] as $day => $amount)
                <div class="flex-1" style="min-width: 3px;"
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

    {{-- ── Row 3 · utilisation over its own range ───────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">How full each seva is</x-slot>
        <x-slot name="description">
            Slots taken against slots actually offered, {{ $util['from']->format('d M Y') }} – {{ $util['to']->format('d M Y') }}.
            @if ($util['capped'])
                <span class="text-warning-600 dark:text-warning-400">Range capped to 92 days.</span>
            @endif
        </x-slot>
        <x-slot name="headerEnd">
            <div class="flex items-center gap-2 text-sm">
                <input type="date" wire:model.live="utilStart"
                       class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
                <span class="text-gray-400">to</span>
                <input type="date" wire:model.live="utilEnd"
                       class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800">
            </div>
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($util['rows'] as $row)
                <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                    <div class="mb-2 flex items-baseline justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{ $row['seva'] }}</span>
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                            @if ($row['offered'])
                                {{ $row['taken'] }} / {{ $row['total'] }} · {{ $row['days'] }} day(s) on
                            @else
                                {{ $row['reason'] }}
                            @endif
                        </span>
                    </div>

                    @if ($row['offered'])
                        <div class="flex items-center gap-2">
                            <div class="h-2 flex-1 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div @class([
                                        'h-2 rounded-full',
                                        'bg-danger-500'  => $row['pct'] >= 100,
                                        'bg-warning-500' => $row['pct'] >= 60 && $row['pct'] < 100,
                                        'bg-success-500' => $row['pct'] < 60,
                                     ])
                                     style="width: {{ max(2, $row['pct']) }}%"></div>
                            </div>
                            <span class="w-10 shrink-0 text-right text-xs font-semibold">{{ $row['pct'] }}%</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- ── Row 4 · calendar (2/3) + the range list beside it (1/3) ───── --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">{{ $calendar['label'] }}</x-slot>
            <x-slot name="headerEnd">
                <div class="flex items-center gap-1">
                    <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="previousMonth" label="Previous month" color="gray" />
                    <x-filament::button size="xs" color="gray" wire:click="thisMonth">Today</x-filament::button>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="nextMonth" label="Next month" color="gray" />
                </div>
            </x-slot>

            <div class="grid grid-cols-7 gap-1.5">
                @foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
                    <div class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $dow }}</div>
                @endforeach

                @foreach ($calendar['weeks'] as $week)
                    @foreach ($week as $cell)
                        <div @class([
                            'relative min-h-[74px] rounded-lg border p-1.5 transition',
                            'border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-900'   => $cell['inMonth'],
                            'border-transparent bg-gray-50/60 dark:bg-gray-900/40'             => ! $cell['inMonth'],
                            'ring-2 ring-primary-500 border-primary-500'                        => $cell['isToday'],
                        ])>
                            <div @class([
                                'mb-1 text-xs font-semibold',
                                'text-gray-400' => ! $cell['inMonth'],
                                'text-primary-600 dark:text-primary-400' => $cell['isToday'],
                            ])>{{ $cell['day'] }}</div>

                            @if ($cell['seva'])
                                <div class="mb-0.5 rounded bg-primary-50 px-1 py-0.5 text-[10px] font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">
                                    {{ $cell['seva'] }} seva
                                </div>
                            @endif
                            @if ($cell['hall'])
                                <div class="rounded bg-warning-50 px-1 py-0.5 text-[10px] font-medium text-warning-700 dark:bg-warning-900/30 dark:text-warning-300">
                                    hall
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>

            <div class="mt-3 flex gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary-500"></span> Seva</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-warning-500"></span> Hall</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Bookings in range</x-slot>
            <x-slot name="description">
                {{ \Illuminate\Support\Carbon::parse($rangeStart)->format('d M Y') }}
                – {{ \Illuminate\Support\Carbon::parse($rangeEnd)->format('d M Y') }}
                · {{ $calendar['printList']->count() }} booking(s)
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::button size="xs" color="primary" icon="heroicon-o-document-arrow-down" wire:click="downloadPdf">
                    PDF
                </x-filament::button>
            </x-slot>

            <div class="max-h-[26rem] space-y-2 overflow-y-auto">
                @forelse ($calendar['printList'] as $row)
                    <div class="border-b border-gray-100 pb-2 text-sm last:border-0 dark:border-gray-800">
                        <div class="flex justify-between gap-2">
                            <span class="truncate font-medium">{{ $row['what'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $row['date'] }}</span>
                        </div>
                        <div class="flex justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="truncate">{{ $row['detail'] }} · {{ $row['who'] }}</span>
                            <span class="shrink-0">{{ inr_money($row['amount']) }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No bookings in this range.</p>
                @endforelse
            </div>

            <p class="mt-3 text-xs text-gray-400">
                The PDF uses the donation range above, laid out landscape with phone numbers and totals.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
