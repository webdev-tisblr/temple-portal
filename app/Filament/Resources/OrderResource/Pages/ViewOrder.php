<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use App\Helpers\NumberToWords;
use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download_invoice')
                ->label('Download Invoice')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn () => ! empty($this->record->invoice_path))
                ->action(function () {
                    return response()->download(
                        storage_path('app/private/' . $this->record->invoice_path)
                    );
                }),

            Actions\Action::make('packing_slip')
                ->label('Packing Slip')
                ->icon('heroicon-o-truck')
                ->color('success')
                ->action(function () {
                    $order = $this->record->load('items', 'devotee');
                    $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
                    $trustAddress = SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205');
                    $trustPhone = SystemSetting::getValue('trust_phone', '');

                    $pdf = Pdf::loadView('invoices.packing-slip', compact('order', 'trustName', 'trustAddress', 'trustPhone'));
                    $pdf->setPaper([0, 0, 288, 432], 'portrait'); // 4x6 inches in points

                    $output = $pdf->output();
                    return response()->streamDownload(
                        fn () => print($output),
                        "PackingSlip_{$order->order_number}.pdf",
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Actions\Action::make('update_status')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('New Status')
                        ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)]))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->record->update(['status' => $data['status']]);

                    Notification::make()
                        ->title('Order status updated to ' . ucfirst($data['status']))
                        ->success()->send();
                }),
        ];
    }
}
