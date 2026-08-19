<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `seva_assignee` recipient strategy — routes a seva trigger to the
 * admin set as Seva Assignee on the booked seva.
 *
 * The contract: the assignee is read from the DISPATCH CONTEXT, never
 * from the template, so one template row serves every seva. Dispatch
 * sites hand the booking over in two different shapes (a live model, or
 * the toArray() bag the receipt/reminder jobs merge), and both have to
 * resolve — a shape that silently misses is a staff message nobody ever
 * notices is missing.
 */
class SevaAssigneeRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function assignee(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Pujari Bhai',
            'email' => 'pujari@example.com',
            'phone' => '9876500001',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }

    private function template(string $channel): NotificationTemplate
    {
        $t = new NotificationTemplate([
            'key' => 'seva.booking.confirmed',
            'channel' => $channel,
        ]);
        $t->recipient_strategy = NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE;

        return $t;
    }

    public function test_resolves_from_a_booking_model_in_context(): void
    {
        $admin = $this->assignee();

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->assignee_id = $admin->id;
        $seva->setRelation('assignee', $admin);

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        $ctx = new NotificationContext(['booking' => $booking]);

        $this->assertSame(
            ['type' => 'phone', 'value' => '9876500001'],
            (new RecipientResolver)->resolve($this->template('whatsapp'), $ctx, 'phone'),
        );
        $this->assertSame(
            ['type' => 'email', 'value' => 'pujari@example.com'],
            (new RecipientResolver)->resolve($this->template('email'), $ctx, 'email'),
        );
    }

    public function test_resolves_from_the_array_bag_jobs_dispatch(): void
    {
        $admin = $this->assignee();

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->assignee_id = $admin->id;
        $seva->setRelation('assignee', $admin);

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        // GenerateSevaReceipt merges the booking as an ARRAY — the nested
        // relation only survives because it loads seva.assignee first.
        $ctx = new NotificationContext(['booking' => $booking->toArray()]);

        $this->assertSame(
            ['type' => 'phone', 'value' => '9876500001'],
            (new RecipientResolver)->resolve($this->template('whatsapp'), $ctx, 'phone'),
        );
    }

    public function test_falls_back_to_the_raw_assignee_id_when_the_relation_was_never_loaded(): void
    {
        $admin = $this->assignee();

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->assignee_id = $admin->id;

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        $ctx = new NotificationContext(['booking' => $booking->toArray()]);

        $this->assertSame(
            ['type' => 'phone', 'value' => '9876500001'],
            (new RecipientResolver)->resolve($this->template('whatsapp'), $ctx, 'phone'),
        );
    }

    public function test_returns_null_when_the_seva_has_no_assignee(): void
    {
        $booking = new SevaBooking;
        $booking->setRelation('seva', new Seva(['name_gu' => 'સેવા']));

        $ctx = new NotificationContext(['booking' => $booking->toArray()]);

        $this->assertNull((new RecipientResolver)->resolve($this->template('whatsapp'), $ctx, 'phone'));
    }

    public function test_the_service_delivers_to_the_assignee_and_injects_admin_into_the_context(): void
    {
        $admin = $this->assignee();

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->setRelation('assignee', $admin);

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        NotificationTemplate::create([
            'key' => 'seva.booking.confirmed',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'label' => 'Seva confirmed — staff copy',
            'is_enabled' => true,
            'subject' => 'New booking',
            'body' => 'Jay Shree Ram {{ admin.name }}',
            'recipients' => [['strategy' => NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE, 'value' => null]],
        ]);

        $delivered = app(NotificationService::class)->dispatchNow(
            'seva.booking.confirmed',
            ['booking' => $booking->toArray()],
        );

        $this->assertSame(1, $delivered);

        $log = NotificationLog::latest('id')->first();
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame(NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE, $log->recipient_strategy);

        $sent = app('mailer')->getSymfonyTransport()->messages()[0];
        $this->assertSame('pujari@example.com', $sent->getEnvelope()->getRecipients()[0]->getAddress());

        // {{ admin.name }} only renders because the service injects the
        // resolved assignee into the per-delivery context.
        $this->assertStringContainsString('Jay Shree Ram Pujari Bhai', $sent->getMessage()->toString());
    }

    public function test_staff_read_gujarati_even_when_the_devotee_reads_hindi(): void
    {
        $admin = $this->assignee();

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->setRelation('assignee', $admin);

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        foreach ([
            NotificationTemplate::RECIPIENT_DEVOTEE,
            NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE,
        ] as $strategy) {
            NotificationTemplate::create([
                'key' => 'seva.booking.confirmed',
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'label' => "Seva confirmed — {$strategy}",
                'is_enabled' => true,
                'subject' => 'New booking',
                'body' => 'Seva: {{ seva_name }}',
                'recipients' => [['strategy' => $strategy, 'value' => null]],
            ]);
        }

        app(NotificationService::class)->dispatchNow('seva.booking.confirmed', [
            'booking' => $booking->toArray(),
            'locale' => 'hi',
            'email' => 'devotee@example.com',
            'seva_name' => 'સેવા',
            'seva_name_hi' => 'सेवा',
        ]);

        $sent = [];
        foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
            $sent[$message->getEnvelope()->getRecipients()[0]->getAddress()] = $message->getMessage()->toString();
        }

        $this->assertArrayHasKey('devotee@example.com', $sent);
        $this->assertArrayHasKey('pujari@example.com', $sent);

        // The devotee keeps their own language; the pujari reads Gujarati
        // off the same dispatch — the split the seva reminder rules make.
        $this->assertStringContainsString('सेवा', $this->decodeBody($sent['devotee@example.com']));
        $this->assertStringContainsString('સેવા', $this->decodeBody($sent['pujari@example.com']));
        $this->assertStringNotContainsString('सेवा', $this->decodeBody($sent['pujari@example.com']));
    }

    /** Symfony encodes the HTML part quoted-printable — decode before matching. */
    private function decodeBody(string $raw): string
    {
        return quoted_printable_decode($raw);
    }

    public function test_the_admin_form_offers_and_saves_the_option_on_a_seva_trigger(): void
    {
        $root = AdminUser::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'secret-password', 'is_active' => true,
        ]);
        Role::findOrCreate('super_admin', 'admin');
        $root->assignRole('super_admin');
        $this->actingAs($root->fresh(), 'admin');

        $template = NotificationTemplate::create([
            'key' => 'seva.booking.confirmed',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'label' => 'Seva confirmed',
            'is_enabled' => true,
            'subject' => 'Hi',
            'body' => 'Hello {{ admin.name }}',
            'recipients' => [['strategy' => NotificationTemplate::RECIPIENT_DEVOTEE, 'value' => null]],
        ]);

        $page = Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->assertOk()
            // Offered because the trigger is a seva.* one — the option list
            // reads the trigger select from OUTSIDE the repeater item.
            ->assertSee('Seva assignee (the staff member assigned to this seva)');

        // Repeater items are keyed by uuid in form state, never by index.
        $uuid = array_key_first($page->get('data')['recipients']);

        $page->set("data.recipients.{$uuid}.strategy", NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE)
            ->call('save')
            ->assertHasNoFormErrors();

        // Saved with NO value — the address is resolved per booking.
        $this->assertSame(
            [['strategy' => NotificationTemplate::RECIPIENT_SEVA_ASSIGNEE, 'value' => null]],
            $template->fresh()->resolveRecipients(),
        );
    }

    public function test_the_admin_form_hides_the_option_on_a_non_seva_trigger(): void
    {
        $root = AdminUser::create([
            'name' => 'Root', 'email' => 'root2@example.test',
            'password' => 'secret-password', 'is_active' => true,
        ]);
        Role::findOrCreate('super_admin', 'admin');
        $root->assignRole('super_admin');
        $this->actingAs($root->fresh(), 'admin');

        $template = NotificationTemplate::create([
            'key' => 'donation.received',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'label' => 'Donation received',
            'is_enabled' => true,
            'subject' => 'Hi',
            'body' => 'Hello',
            'recipients' => [['strategy' => NotificationTemplate::RECIPIENT_DEVOTEE, 'value' => null]],
        ]);

        // A donation context carries no seva, so the option would only ever
        // resolve to nothing — it must not be offered there.
        Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->assertOk()
            ->assertDontSee('Seva assignee');
    }

    public function test_returns_null_when_the_assignee_has_no_address_for_the_channel(): void
    {
        $admin = AdminUser::create([
            'name' => 'No Phone Bhai',
            'email' => 'nophone@example.com',
            'phone' => null,
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $seva = new Seva(['name_gu' => 'સેવા']);
        $seva->setRelation('assignee', $admin);

        $booking = new SevaBooking;
        $booking->setRelation('seva', $seva);

        $ctx = new NotificationContext(['booking' => $booking]);

        $this->assertNull((new RecipientResolver)->resolve($this->template('whatsapp'), $ctx, 'phone'));
        $this->assertSame(
            ['type' => 'email', 'value' => 'nophone@example.com'],
            (new RecipientResolver)->resolve($this->template('email'), $ctx, 'email'),
        );
    }
}
