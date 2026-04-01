<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Donation;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinancialReports extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Donations & Finance';
    protected static ?string $title = 'Financial Reports';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.financial-reports';

    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $donation_type = null;
    public ?string $financial_year = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->toDateString();
    }

    protected function getForms(): array
    {
        return [
            'filterForm',
        ];
    }

    public function filterForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('date_from')->label('From'),
            Forms\Components\DatePicker::make('date_to')->label('To'),
            Forms\Components\Select::make('donation_type')->options([
                '' => 'All', 'general' => 'General', 'seva' => 'Seva', 'annadan' => 'Annadan',
                'construction' => 'Construction', 'festival' => 'Festival',
            ])->placeholder('All Types'),
            Forms\Components\Select::make('financial_year')->options(
                Donation::distinct()->pluck('financial_year', 'financial_year')->toArray()
            )->placeholder('All Years'),
        ])->columns(4)->statePath('');
    }

    public function applyFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Donation::query()
                    ->with('devotee', 'receipt')
                    ->when($this->date_from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->date_from))
                    ->when($this->date_to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->date_to))
                    ->when($this->donation_type, fn (Builder $q) => $q->where('donation_type', $this->donation_type))
                    ->when($this->financial_year, fn (Builder $q) => $q->where('financial_year', $this->financial_year))
            )
            ->columns([
                Tables\Columns\TextColumn::make('receipt.receipt_number')->label('Receipt No.')->default('-'),
                Tables\Columns\TextColumn::make('created_at')->date('d/m/Y')->label('Date')->sortable(),
                Tables\Columns\TextColumn::make('devotee.name')->label('Devotee')->default('Anonymous'),
                Tables\Columns\TextColumn::make('amount')->prefix('₹')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->prefix('₹')->label('Total')),
                Tables\Columns\TextColumn::make('donation_type')->badge()->label('Type'),
                Tables\Columns\TextColumn::make('financial_year')->label('FY'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function getSummary(): array
    {
        $query = Donation::query()
            ->when($this->date_from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->date_from))
            ->when($this->date_to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->date_to))
            ->when($this->donation_type, fn (Builder $q) => $q->where('donation_type', $this->donation_type))
            ->when($this->financial_year, fn (Builder $q) => $q->where('financial_year', $this->financial_year));

        return [
            'total' => number_format((float) $query->sum('amount'), 2),
            'count' => $query->count(),
            'average' => $query->count() > 0 ? number_format((float) $query->avg('amount'), 2) : '0.00',
        ];
    }
}
