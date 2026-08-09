<x-filament-panels::page>
    <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950">
        <strong>This creates real financial records.</strong>
        Every entry produces a captured payment and fires exactly the same receipts,
        invoices, greeting cards, notifications, stock movements and seva reminders as
        an online payment. There is no draft state and no undo &mdash; a mistake has to
        be cancelled/refunded from the relevant record afterwards.
    </div>

    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-banknotes">
                Record payment &amp; confirm
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
