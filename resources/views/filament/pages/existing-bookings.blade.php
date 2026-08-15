<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button
            wire:click="download"
            icon="heroicon-o-arrow-down-tray"
            color="gray"
        >
            Download current sheet (CSV)
        </x-filament::button>

        <span class="text-sm text-gray-500 dark:text-gray-400">
            Every date currently blocked or booked, ready to fill in.
        </span>
    </div>

    <form wire:submit.prevent="preview">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            {{-- Preview first: this closes real dates, and a mistyped date is
                 invisible until a devotee cannot book. --}}
            <x-filament::button type="submit" color="gray" icon="heroicon-o-eye">
                Preview (changes nothing)
            </x-filament::button>

            <x-filament::button
                wire:click="import"
                color="primary"
                icon="heroicon-o-check"
                wire:confirm="This writes real bookings and closes real dates. Preview first if you have not. Continue?"
            >
                Import for real
            </x-filament::button>
        </div>
    </form>

    @if ($result)
        @php
            $stats = $result['stats'];
            $failed = $stats['failed'] > 0;
        @endphp

        <x-filament::section :heading="$result['dry_run'] ? 'Preview — nothing was saved' : 'Import result'">
            <div class="mb-4 flex flex-wrap gap-4 text-sm">
                <span><strong>{{ $stats['blocked'] }}</strong> date(s) blocked</span>
                <span><strong>{{ $stats['booked'] }}</strong> booking(s)</span>
                <span><strong>{{ $stats['already'] }}</strong> already present</span>
                <span @class(['text-danger-600 dark:text-danger-400 font-semibold' => $failed])>
                    <strong>{{ $stats['failed'] }}</strong> failed
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pe-4">Line</th>
                            <th class="py-2 pe-4">Status</th>
                            <th class="py-2">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['lines'] as $line)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-1.5 pe-4 font-mono">{{ $line['line'] }}</td>
                                <td @class([
                                    'py-1.5 pe-4 font-medium',
                                    'text-danger-600 dark:text-danger-400' => $line['status'] === 'failed',
                                    'text-gray-500 dark:text-gray-400' => $line['status'] === 'already',
                                ])>{{ $line['status'] }}</td>
                                <td class="py-1.5">{{ $line['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
