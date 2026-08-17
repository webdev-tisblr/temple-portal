<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AdminUser;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Models\Hall;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seva;
use App\Services\CounterEntryService;
use App\Services\HallAvailabilityService;
use App\Services\SevaSlotService;
use App\Support\PhoneNumber;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Item 6.1 — Counter Entry. ONE page, four record types, one permission.
 *
 * WHY ONE PAGE RATHER THAN FOUR `Create` ACTIONS
 * ----------------------------------------------
 *  • DonationResource, SevaBookingResource, HallBookingResource and
 *    OrderResource all hard-return `canCreate() === false`, with the
 *    SevaBooking one spelling out why: "Leave this hard return so even a
 *    super admin can't accidentally insert a booking with no payment."
 *    Those four rails are LEFT EXACTLY AS THEY ARE. This page is the only
 *    create path, and it cannot produce a record without a captured
 *    Payment — so the guarantee those rails exist to protect is kept,
 *    not weakened.
 *  • The devotee find-or-create step is identical for all four and is the
 *    slow part of a counter interaction. Building it once is the
 *    difference between a 20-second entry and a 90-second one.
 *  • "Who is allowed to take cash" becomes ONE auditable switch
 *    (`page_CounterEntryPage`) instead of four create permissions spread
 *    across three navigation groups.
 *
 * All the money logic lives in CounterEntryService — this class is a form
 * and nothing more, so the behaviour is testable without Livewire.
 *
 * Filament 3: every closure parameter is type-hinted (`Get $get`,
 * `Builder $query`). A bare `fn ($q)` throws BindingResolutionException.
 */
class CounterEntryPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Booking & Donation Reports';

    protected static ?string $title = 'Counter Entry (Cash / Offline)';

    protected static ?string $navigationLabel = 'Counter Entry';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.counter-entry';

    /**
     * The cash gate. Fail-CLOSED: a Page's canAccess() consults the
     * permission directly, so an admin without it is denied both the
     * navigation entry and the direct URL. Super admin passes through
     * Gate::before in AuthServiceProvider.
     */
    public static function canAccess(): bool
    {
        return auth('admin')->user()?->can('page_CounterEntryPage') ?? false;
    }

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->blankState());
    }

    /** @return array<string,mixed> */
    private function blankState(): array
    {
        $allowed = $this->service()->allowedTypesFor($this->admin());

        return [
            // Minted once per interaction; becomes the synthetic Payment's
            // razorpay_order_id, whose UNIQUE index makes a double-submit
            // impossible. Re-minted after every successful save.
            'entry_token' => CounterEntryService::newEntryToken(),
            'record_type' => $allowed[0] ?? null,
            'payment_method' => 'cash',
            'paid_on' => now()->toDateString(),
            'quantity' => 1,
            'wants_80g' => true,
            'anonymous' => false,
            'donation_type' => 'general',
            'devotee_language' => 'gu',
            'items' => [],
        ];
    }

    private function admin(): ?AdminUser
    {
        $user = auth('admin')->user();

        return $user instanceof AdminUser ? $user : null;
    }

    private function service(): CounterEntryService
    {
        return app(CounterEntryService::class);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->devoteeSection(),
                $this->recordSection(),
                $this->paymentSection(),
            ])
            ->statePath('data');
    }

    // ── Step 1 — devotee ─────────────────────────────────────────────

    private function devoteeSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('1 — Devotee')
            ->icon('heroicon-o-user')
            ->description('Type the mobile number. An existing devotee is matched instantly; otherwise fill in the new-devotee fields.')
            ->schema([
                Forms\Components\Hidden::make('entry_token'),

                Forms\Components\TextInput::make('phone')
                    ->label('Mobile number')
                    ->tel()
                    ->required()
                    ->maxLength(20)
                    // onBlur rather than every keystroke: one query per
                    // number typed, not ten.
                    ->live(onBlur: true)
                    ->helperText('Stored canonically (bare 10 digits for India) — the same key the OTP login uses.'),

                Forms\Components\Placeholder::make('devotee_lookup')
                    ->label('Match')
                    ->content(function (Get $get): string {
                        $raw = (string) ($get('phone') ?? '');
                        if (trim($raw) === '') {
                            return 'Enter a mobile number.';
                        }

                        $canonical = PhoneNumber::normalize($raw);
                        if ($canonical === null) {
                            return '⚠ Not a valid mobile number.';
                        }

                        $devotee = $this->service()->findDevoteeByPhone($raw);
                        if ($devotee === null) {
                            return "No devotee on {$canonical} — a new record will be created below.";
                        }

                        $pan = filled($devotee->pan_encrypted) ? 'PAN on file' : 'no PAN (80G not possible)';
                        $city = $devotee->city ?: '—';

                        return "✓ {$devotee->name} · {$city} · {$pan}";
                    }),

                Forms\Components\Group::make([
                    Forms\Components\TextInput::make('devotee_name')
                        ->label('Name (new devotee)')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('devotee_email')
                        ->label('Email (optional)')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('devotee_city')
                        ->label('City (optional)')
                        ->maxLength(255),
                    Forms\Components\Select::make('devotee_language')
                        ->label('Language for their receipts')
                        ->options(['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'])
                        ->default('gu'),
                ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $this->service()->findDevoteeByPhone((string) ($get('phone') ?? '')) === null),
            ])
            ->columns(2);
    }

    // ── Step 2 — what are they paying for ────────────────────────────

    private function recordSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('2 — What are they paying for?')
            ->icon('heroicon-o-clipboard-document-list')
            ->schema([
                Forms\Components\ToggleButtons::make('record_type')
                    ->label('')
                    ->inline()
                    ->required()
                    ->live()
                    // Only the types this admin actually holds the matching
                    // create_* permission for. The service re-checks on
                    // submit, so hiding the button is convenience, not the
                    // control.
                    ->options(fn (): array => collect($this->service()->allowedTypesFor($this->admin()))
                        ->mapWithKeys(fn (string $type): array => [$type => match ($type) {
                            CounterEntryService::TYPE_DONATION => 'Donation',
                            CounterEntryService::TYPE_SEVA => 'Seva Booking',
                            CounterEntryService::TYPE_HALL => 'Hall Booking',
                            CounterEntryService::TYPE_STORE => 'Store Order',
                            default => $type,
                        }])
                        ->all()),

                $this->donationFields(),
                $this->sevaFields(),
                $this->hallFields(),
                $this->storeFields(),
            ]);
    }

    private function donationFields(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Donation details')
            ->visible(fn (Get $get): bool => $get('record_type') === CounterEntryService::TYPE_DONATION)
            ->schema([
                // The hardcoded Category select that sat here until
                // 2026-08-13 is gone. It offered a second, parallel list of
                // types that no admin could edit, so a counter entry could be
                // filed under a category the trust had never configured —
                // and the type the trust DID configure had to be picked
                // separately, in the field below. One list now, the one the
                // trust manages. The legacy donation_type column is still
                // written, derived from the chosen type's slug, so reports
                // and receipts on existing rows keep working.
                Forms\Components\Select::make('donation_type_id')
                    ->label('Donation type')
                    // ->get()->pluck() and NOT a raw pluck: `name` is a
                    // localized ACCESSOR, not a column. A raw pluck selects
                    // a column that does not exist and 500s the page — the
                    // exact bug that took the Home Page Settings page down.
                    ->options(fn (): array => DonationType::query()
                        ->where('is_active', true)
                        ->get()
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->helperText('Managed under Donations → Donation Types.'),

                Forms\Components\Select::make('campaign_id')
                    ->label('Campaign (optional)')
                    ->options(fn (): array => DonationCampaign::query()
                        ->where('is_active', true)
                        ->get()
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable()
                    ->live(),

                Forms\Components\TextInput::make('amount')
                    ->label('Amount received (₹)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->live(onBlur: true),

                Forms\Components\TextInput::make('purpose')
                    ->label('Purpose (printed on the receipt)')
                    ->maxLength(255),

                Forms\Components\Toggle::make('wants_80g')
                    ->label('Donor wants an 80G receipt')
                    ->default(true)
                    ->helperText('Strict rule (item 5.4): with no valid PAN on the donor profile, NO 80G receipt is issued and no receipt number is burnt. The donation is still recorded in the donor\'s name — it does NOT become Gupt Daan.'),

                Forms\Components\Toggle::make('anonymous')
                    ->label('Gupt Daan (hide from public donor lists)')
                    ->helperText('Only tick this if the devotee asked to stay anonymous. It masks the name on the public donor lists and nothing else — full details stay in admin, and it does not affect the 80G receipt.'),
            ])
            ->columns(2);
    }

    /** The seva currently chosen in the form, or null. */
    private static function sevaFor(Get $get): ?Seva
    {
        return filled($get('seva_id')) ? Seva::find($get('seva_id')) : null;
    }

    /**
     * Products this seva offers a choice of — already stock-filtered by the
     * model, so the counter never lists something the shelf cannot supply.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private static function linkedProducts(Get $get): \Illuminate\Support\Collection
    {
        $seva = self::sevaFor($get);

        return $seva?->hasProductSelection() ? $seva->getLinkedProductsList() : collect();
    }

    /**
     * How far ahead the counter's date picker looks when working out which
     * dates to grey out. Bounded because the answer is computed per date; a
     * seva with an open-ended acceptance window would otherwise be unbounded.
     */
    private const DATE_HORIZON_DAYS = 180;

    /** @var array<int, list<string>> seva id => unavailable dates, per request */
    private static array $unavailableDatesMemo = [];

    /**
     * Dates this seva cannot be booked on, for the picker to disable.
     *
     * Same source of truth the website and app calendars use, so a clerk sees
     * exactly what a devotee would. Memoised per request: the picker re-reads
     * this on every live form render and each call is a bulk query.
     *
     * @return list<string>
     */
    private static function unavailableDates(Get $get): array
    {
        $seva = self::sevaFor($get);
        if ($seva === null) {
            return [];
        }

        return self::$unavailableDatesMemo[$seva->id] ??= collect(
            app(SevaSlotService::class)->getDateAvailabilityInRange(
                $seva,
                now()->startOfDay(),
                now()->startOfDay()->addDays(self::DATE_HORIZON_DAYS),
            )
        )
            ->reject(fn (array $day): bool => (bool) ($day['available'] ?? false))
            ->pluck('date')
            ->values()
            ->all();
    }

    /** @var array<int, list<string>> hall id => unavailable dates, per request */
    private static array $unavailableHallDatesMemo = [];

    /**
     * Dates the chosen hall cannot be booked on — already let, blacked out by
     * an admin, or past the cut-off. Same source the website's hall calendar
     * reads, so the counter and the public site agree.
     *
     * @return list<string>
     */
    private static function unavailableHallDates(Get $get): array
    {
        $hall = filled($get('hall_id')) ? Hall::find($get('hall_id')) : null;
        if ($hall === null) {
            return [];
        }

        return self::$unavailableHallDatesMemo[$hall->id] ??= collect(
            app(HallAvailabilityService::class)->rangeAvailability(
                $hall,
                now()->startOfDay(),
                now()->startOfDay()->addDays(self::DATE_HORIZON_DAYS),
            )
        )
            ->reject(fn (array $day): bool => (bool) ($day['available'] ?? false))
            ->pluck('date')
            ->values()
            ->all();
    }

    /** full_day | full_week | time for the chosen seva, or null if none picked. */
    private static function slotTypeFor(Get $get): ?string
    {
        $seva = self::sevaFor($get);
        if ($seva === null) {
            return null;
        }

        $slots = app(SevaSlotService::class);

        return $slots->slotType($slots->configFor($seva));
    }

    /**
     * Bookable slot strings for the chosen seva AND date. Empty until a date
     * is picked — availability is per-date, so there is genuinely nothing to
     * offer before then.
     *
     * @return array<string, string>
     */
    private static function availableSlots(Get $get): array
    {
        $seva = self::sevaFor($get);
        $date = $get('booking_date');
        if ($seva === null || blank($date)) {
            return [];
        }

        // `available` is a flat list of slot strings (the shape App 1.4.8+32
        // reads) — not a list of maps.
        $slots = app(SevaSlotService::class)->getSlotAvailability(
            $seva,
            Carbon::parse((string) $date)->toDateString(),
        );

        return collect($slots['available'] ?? [])
            ->mapWithKeys(fn (string $slot): array => [$slot => $slot])
            ->all();
    }

    /** The product picked in the form, resolved from the seva's own list. */
    private static function selectedProduct(Get $get): ?Product
    {
        if (blank($get('selected_product_id'))) {
            return null;
        }

        // Deliberately from the seva's list rather than Product::find(): a
        // stale id (seva changed, product sold out) must resolve to null so
        // the variant picker disappears instead of offering dead options.
        return self::linkedProducts($get)->firstWhere('id', (int) $get('selected_product_id'));
    }

    private function sevaFields(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Seva booking details')
            ->visible(fn (Get $get): bool => $get('record_type') === CounterEntryService::TYPE_SEVA)
            ->schema([
                Forms\Components\Select::make('seva_id')
                    ->label('Seva')
                    ->options(fn (): array => Seva::query()
                        ->where('is_active', true)
                        ->get()
                        ->pluck('name_en', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    // A product/variant/slot chosen for the previous seva is
                    // not valid for this one.
                    ->afterStateUpdated(function (Set $set): void {
                        $set('selected_product_id', null);
                        $set('selected_variant_label', null);
                        $set('slot_time', null);
                    }),

                Forms\Components\DatePicker::make('booking_date')
                    ->label('Booking date')
                    ->native(false)
                    ->required()
                    ->live()
                    // Grey out every date this seva cannot take: blackouts,
                    // the wrong weekday, outside the acceptance window, past
                    // the cut-off, and — the one that matters here — already
                    // full. A clerk could previously pick a fully-booked date,
                    // and only the capacity re-check at submit stopped the
                    // double booking, after the money conversation had already
                    // happened (2026-08-17).
                    //
                    // native(false) is load-bearing: the browser's own date
                    // input ignores disabledDates.
                    ->minDate(now()->startOfDay())
                    ->maxDate(now()->startOfDay()->addDays(self::DATE_HORIZON_DAYS))
                    ->disabledDates(fn (Get $get): array => self::unavailableDates($get))
                    // Slots are per-date, so yesterday's pick may not exist on
                    // the new date — clear it rather than submit a stale one.
                    ->afterStateUpdated(fn (Set $set) => $set('slot_time', null)),

                // Sevas that carry a product choice (prasad, vastra, …). The
                // service has always priced and persisted these two keys, but
                // the counter had no picker for them — so a walk-in booking of
                // a product-linked seva silently recorded no product and
                // charged the seva's own price (2026-08-17).
                //
                // Sold-out products are already absent from
                // getLinkedProductsList(), so the counter cannot sell what the
                // website and app are refusing to offer.
                Forms\Components\Select::make('selected_product_id')
                    ->label(fn (Get $get): string => self::sevaFor($get)?->getProductSelectionLabel() ?? 'Product')
                    ->options(fn (Get $get): array => self::linkedProducts($get)
                        ->mapWithKeys(fn (Product $p): array => [
                            $p->id => ($p->name_en ?: $p->name_gu).' — '.$p->getDisplayPrice(),
                        ])
                        ->all())
                    ->visible(fn (Get $get): bool => self::linkedProducts($get)->isNotEmpty())
                    ->required(fn (Get $get): bool => self::linkedProducts($get)->isNotEmpty())
                    ->searchable()
                    ->live()
                    // A variant label from the previously chosen product would
                    // not exist on the new one, and getVariantPrice() would
                    // quietly fall back to the seva price.
                    ->afterStateUpdated(fn (Set $set) => $set('selected_variant_label', null))
                    ->helperText(fn (Get $get): ?string => self::sevaFor($get)?->hasProductSelection()
                        && self::linkedProducts($get)->isEmpty()
                            ? 'Every option for this seva is out of stock — it cannot be booked right now.'
                            : null),

                Forms\Components\Select::make('selected_variant_label')
                    ->label('Option')
                    ->options(fn (Get $get): array => collect(self::selectedProduct($get)?->variants ?? [])
                        ->mapWithKeys(fn (array $v): array => [
                            ($v['label'] ?? '') => ($v['label'] ?? '')
                                .(((float) ($v['price'] ?? 0)) > 0 ? ' — ₹'.inr((float) $v['price']) : ''),
                        ])
                        ->all())
                    // Shown-but-unselectable when sold out, matching how the
                    // website and app present a spent variant.
                    ->disableOptionWhen(function (string $value, Get $get): bool {
                        $product = self::selectedProduct($get);

                        return $product !== null
                            && $product->track_stock
                            && (int) ($product->getVariantStock($value) ?? 0) <= 0;
                    })
                    ->visible(fn (Get $get): bool => (bool) self::selectedProduct($get)?->has_variants)
                    ->required(fn (Get $get): bool => (bool) self::selectedProduct($get)?->has_variants)
                    ->live(),

                Forms\Components\Select::make('slot_time')
                    ->label('Slot')
                    ->options(fn (Get $get): array => self::availableSlots($get))
                    // Hidden entirely for a full-day / full-week seva: there
                    // is no time slot to pick and the server sets the sentinel
                    // itself. It used to render as a permanently empty
                    // dropdown, which read as "the slots are broken".
                    ->visible(fn (Get $get): bool => self::slotTypeFor($get) === SevaSlotService::SLOT_TYPE_TIME)
                    // Slots depend on the DATE, so there is nothing to list
                    // until one is chosen — say so rather than showing an
                    // empty, enabled dropdown (2026-08-17).
                    ->disabled(fn (Get $get): bool => blank($get('booking_date')))
                    ->required(fn (Get $get): bool => self::slotTypeFor($get) === SevaSlotService::SLOT_TYPE_TIME
                        && filled($get('booking_date')))
                    ->live()
                    ->helperText(function (Get $get): ?string {
                        if (blank($get('seva_id'))) {
                            return null;
                        }
                        if (blank($get('booking_date'))) {
                            return 'Choose a booking date first — slots differ per date.';
                        }

                        return self::availableSlots($get) === []
                            ? 'No slots left on this date. Pick another date.'
                            : null;
                    }),

                Forms\Components\TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->live(onBlur: true),

                Forms\Components\TextInput::make('seva_amount')
                    ->label('Amount per unit (₹)')
                    ->numeric()
                    ->live(onBlur: true)
                    ->helperText('Only used for a variable-price seva; otherwise the seva price applies.')
                    ->visible(fn (Get $get): bool => (bool) (filled($get('seva_id')) ? Seva::find($get('seva_id'))?->is_variable_price : false)),

                Forms\Components\TextInput::make('devotee_name_for_seva')
                    ->label('Name to be taken in the seva')
                    ->maxLength(255),

                Forms\Components\Textarea::make('sankalp')
                    ->label('Sankalp')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private function hallFields(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Hall booking details')
            ->visible(fn (Get $get): bool => $get('record_type') === CounterEntryService::TYPE_HALL)
            ->schema([
                Forms\Components\Select::make('hall_id')
                    ->label('Hall')
                    // ->get()->pluck() and NOT a raw pluck, for the same
                    // reason as the donation type above: `name` is a
                    // localized ACCESSOR. temple_halls does still have a
                    // legacy `name` COLUMN, so a raw pluck silently read that
                    // stale pre-multilingual value instead of the name the
                    // admin maintains — halls showed the wrong label, and one
                    // renamed since the migration showed its old name
                    // (2026-08-17). Ordering has to move off the column too.
                    ->options(fn (): array => Hall::query()
                        ->where('is_active', true)
                        ->orderBy('name_gu')
                        ->get()
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->live()
                    // Availability is per hall, so a date picked for the
                    // previous one means nothing here.
                    ->afterStateUpdated(function (Set $set): void {
                        $set('hall_booking_date', null);
                        $set('hall_end_date', null);
                    }),

                Forms\Components\DatePicker::make('hall_booking_date')
                    ->label('From date')
                    ->native(false)
                    ->required()
                    ->live()
                    // Dates already taken (or blacked out, or past the
                    // cut-off) are greyed out, so the counter cannot
                    // double-book a hall that the website has already let.
                    ->minDate(now()->startOfDay())
                    ->maxDate(now()->startOfDay()->addDays(self::DATE_HORIZON_DAYS))
                    ->disabledDates(fn (Get $get): array => self::unavailableHallDates($get))
                    ->afterStateUpdated(fn (Set $set) => $set('hall_end_date', null)),

                Forms\Components\DatePicker::make('hall_end_date')
                    ->label('To date (leave blank for a single day)')
                    ->native(false)
                    ->live()
                    // Same blocked set. The range is still re-checked
                    // server-side, which is what catches a date taken while
                    // this form was open.
                    ->minDate(fn (Get $get) => filled($get('hall_booking_date'))
                        ? Carbon::parse((string) $get('hall_booking_date'))
                        : now()->startOfDay())
                    ->maxDate(now()->startOfDay()->addDays(self::DATE_HORIZON_DAYS))
                    ->disabledDates(fn (Get $get): array => self::unavailableHallDates($get))
                    ->helperText('Multi-day ranges are priced as flat rate × days and block every date in between.'),

                Forms\Components\TextInput::make('hall_purpose')
                    ->label('Purpose')
                    ->required()
                    ->maxLength(500),

                Forms\Components\TextInput::make('expected_guests')
                    ->label('Expected guests')
                    ->numeric(),

                Forms\Components\TextInput::make('contact_name')
                    ->label('Contact name (defaults to the devotee)')
                    ->maxLength(255),

                Forms\Components\TextInput::make('contact_phone')
                    ->label('Contact phone (defaults to the devotee)')
                    ->tel()
                    ->maxLength(15),
            ])
            ->columns(2);
    }

    private function storeFields(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Store order details')
            ->visible(fn (Get $get): bool => $get('record_type') === CounterEntryService::TYPE_STORE)
            ->schema([
                Forms\Components\Repeater::make('items')
                    ->label('Items')
                    ->live()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Product')
                            // Two fixes, 2026-08-17:
                            //  • name_en is optional — most products are named
                            //    in Gujarati only, so plucking it produced a
                            //    list of BLANK labels and the picker looked
                            //    empty. `name` is the localized accessor, so
                            //    this needs ->get()->pluck() (a raw pluck
                            //    would read no column at all).
                            //  • forStore() instead of a bare is_seva_only
                            //    check: it also excludes products sitting in a
                            //    seva-only CATEGORY, which is how most
                            //    seva-only stock is actually marked. Those are
                            //    not for sale over the counter.
                            ->options(fn (): array => Product::query()
                                ->active()
                                ->forStore()
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (Product $p): array => [
                                    $p->id => $p->name.' — '.$p->getDisplayPrice(),
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            // A variant from the previous product is not valid
                            // for this one.
                            ->afterStateUpdated(fn (Set $set) => $set('variant_label', null)),

                        Forms\Components\Select::make('variant_label')
                            ->label('Option')
                            ->options(function (Get $get): array {
                                $product = filled($get('product_id')) ? Product::find($get('product_id')) : null;
                                if ($product === null || ! $product->has_variants) {
                                    return [];
                                }

                                return collect($product->variants ?? [])
                                    ->mapWithKeys(fn (array $v): array => [
                                        ($v['label'] ?? '') => ($v['label'] ?? '').' (stock '.($v['stock'] ?? 0).')',
                                    ])
                                    ->all();
                            })
                            ->live()
                            // Required once the product has options, or the
                            // sale records no variant and decrements the wrong
                            // stock bucket.
                            ->required(fn (Get $get): bool => (bool) (filled($get('product_id')) ? Product::find($get('product_id'))?->has_variants : false))
                            ->visible(fn (Get $get): bool => (bool) (filled($get('product_id')) ? Product::find($get('product_id'))?->has_variants : false)),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Qty')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('counter_sale_note')
                    ->label('Shipping')
                    ->columnSpanFull()
                    ->content('A counter sale has no delivery. The six NOT NULL shipping_* columns are filled with counter-sale defaults (address line rendered in the devotee\'s own language on the invoice). Fill the fields below only if the devotee wants it posted.'),

                Forms\Components\TextInput::make('shipping_address')->label('Delivery address (optional)')->maxLength(500)->columnSpanFull(),
                Forms\Components\TextInput::make('shipping_city')->label('City (optional)')->maxLength(100),
                Forms\Components\TextInput::make('shipping_pincode')->label('Pincode (optional)')->maxLength(10),
            ])
            ->columns(2);
    }

    // ── Step 3 — payment ─────────────────────────────────────────────

    private function paymentSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('3 — Payment')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Select::make('payment_method')
                    ->label('How was it paid?')
                    ->options(Payment::OFFLINE_METHODS)
                    ->default('cash')
                    ->required(),

                Forms\Components\TextInput::make('reference_note')
                    ->label('Reference (cheque no. / UPI txn ref)')
                    ->maxLength(120),

                Forms\Components\DatePicker::make('paid_on')
                    ->label('Money received on')
                    ->native(false)
                    ->default(now())
                    ->maxDate(now())
                    ->required()
                    ->helperText('Back-dating is allowed for cash taken earlier. It sets the payment date, the record date and — for donations — the financial year the receipt is issued in.'),

                Forms\Components\Textarea::make('notes')
                    ->label('Internal notes')
                    ->rows(2),

                Forms\Components\Placeholder::make('total_preview')
                    ->label('Total to record')
                    ->columnSpanFull()
                    ->content(function (Get $get): string {
                        // Server-authoritative preview: the same quote()
                        // the save path recomputes. The browser never
                        // decides the amount.
                        $quote = $this->service()->quote($this->currentState($get));

                        if ($quote['total'] <= 0) {
                            return 'Complete the details above to see the total.';
                        }

                        $detail = $quote['detail'] ? " ({$quote['detail']})" : '';

                        return '₹'.number_format($quote['total'], 2).' — '.$quote['label'].$detail;
                    }),
            ])
            ->columns(2);
    }

    /**
     * Read the whole form state through Get, which is the only handle a
     * Placeholder closure has on sibling fields.
     *
     * @return array<string,mixed>
     */
    private function currentState(Get $get): array
    {
        $keys = [
            'record_type', 'amount', 'purpose', 'seva_id', 'quantity', 'seva_amount',
            'selected_product_id', 'selected_variant_label', 'hall_id',
            'hall_booking_date', 'hall_end_date', 'items',
        ];

        $state = [];
        foreach ($keys as $key) {
            $state[$key] = $get($key);
        }

        return $state;
    }

    // ── Save ─────────────────────────────────────────────────────────

    public function submit(): void
    {
        $admin = $this->admin();
        if ($admin === null) {
            return;
        }

        $data = $this->form->getState();

        try {
            $result = $this->service()->record($data, $admin);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not record this entry')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        } catch (\Throwable $e) {
            Log::error('CounterEntry: failed to record a counter payment', [
                'admin_id' => $admin->getKey(),
                'record_type' => $data['record_type'] ?? null,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Something went wrong')
                ->body('Nothing was recorded. Check the details and try again.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($result['duplicate']) {
            Notification::make()
                ->title('Already recorded')
                ->body('This entry had already been saved — nothing was duplicated.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Recorded and confirmed')
                ->body($this->successBody($result))
                ->success()
                ->persistent()
                ->send();
        }

        // Fresh token + blank form, ready for the next devotee in the
        // queue. Re-minting is what makes the NEXT entry a new entry.
        $this->form->fill($this->blankState());
    }

    /** @param array<string,mixed> $result */
    private function successBody(array $result): string
    {
        $amount = '₹'.number_format((float) $result['payment']->amount, 2);
        $who = $result['devotee']?->name ?: 'devotee';

        $reference = match ($result['type']) {
            CounterEntryService::TYPE_SEVA => $result['record']->receipt_number,
            CounterEntryService::TYPE_STORE => $result['record']->order_number,
            CounterEntryService::TYPE_DONATION => $result['record']->receipt?->receipt_number,
            default => null,
        };

        return trim("{$amount} recorded for {$who}. {$result['label']}."
            .($reference ? " Reference: {$reference}." : '')
            .' Receipts and messages have been dispatched exactly as for an online payment.');
    }

    /**
     * Convenience list for FinancialReports-style reconciliation queries:
     * every offline payment this admin took. Kept here so the LIKE pattern
     * has one home.
     */
    public static function offlinePaymentsQuery(): Builder
    {
        return Payment::query()
            ->where('razorpay_order_id', 'like', Payment::OFFLINE_ORDER_PREFIX.'%');
    }
}
