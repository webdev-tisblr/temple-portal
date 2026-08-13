<x-filament-panels::page>
    {{-- Filters and their actions live in a section, not loose on the page.
         Without it the four filter fields ran edge to edge and the buttons
         sat straight under them with no separation, which read as one
         cramped block rather than a control panel. --}}
    <x-filament::section>
        <form wire:submit.prevent="">
            {{ $this->filterForm }}

            {{-- Stacked and full width on a phone, inline from `sm` up. The
                 old `flex-wrap gap-2` left them squeezed together on desktop
                 and wrapped them into a ragged column on narrow screens. --}}
            <div class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:flex-wrap sm:items-center dark:border-white/10">
                <x-filament::button wire:click="applyFilters" icon="heroicon-m-funnel"
                                    class="w-full justify-center sm:w-auto">Apply Filters</x-filament::button>
                <x-filament::button wire:click="exportCsv" color="success" icon="heroicon-m-table-cells"
                                    class="w-full justify-center sm:w-auto">Export CSV</x-filament::button>
                <x-filament::button wire:click="exportPdf" color="warning" icon="heroicon-m-document-arrow-down"
                                    class="w-full justify-center sm:w-auto">Export PDF</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
        @php $summary = $this->getSummary(); @endphp
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
            <p class="text-sm text-gray-500">Total Amount</p>
            <p class="text-2xl font-bold text-primary-600">₹{{ $summary['total'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
            <p class="text-sm text-gray-500">Total Donations</p>
            <p class="text-2xl font-bold">{{ $summary['count'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
            <p class="text-sm text-gray-500">Average Amount</p>
            <p class="text-2xl font-bold">₹{{ $summary['average'] }}</p>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
