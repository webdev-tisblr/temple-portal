<x-filament-panels::page>
    <form wire:submit.prevent="">
        {{ $this->filterForm }}
        <div class="mt-4 flex flex-wrap gap-2">
            <x-filament::button wire:click="applyFilters" icon="heroicon-m-funnel">Apply Filters</x-filament::button>
            <x-filament::button wire:click="exportCsv" color="success" icon="heroicon-m-table-cells">Export CSV</x-filament::button>
            <x-filament::button wire:click="exportPdf" color="warning" icon="heroicon-m-document-arrow-down">Export PDF</x-filament::button>
        </div>
    </form>

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
