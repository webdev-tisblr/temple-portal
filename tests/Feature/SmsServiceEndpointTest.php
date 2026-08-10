<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsServiceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $apiUrl): SmsService
    {
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_api_url'], ['value' => $apiUrl]);
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_auth_key'], ['value' => 'test-key']);
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_sender_id'], ['value' => 'SPHST']);

        return new SmsService;
    }

    /** The production value already ended in /flow, which used to double up. */
    public function test_the_flow_endpoint_is_correct_however_the_admin_typed_the_base_url(): void
    {
        $expected = 'https://control.msg91.com/api/v5/flow/';

        foreach ([
            'https://control.msg91.com/api/v5',
            'https://control.msg91.com/api/v5/',
            'https://control.msg91.com/api/v5/flow',   // the live setting
            'https://control.msg91.com/api/v5/flow/',
        ] as $input) {
            $this->assertSame($expected, $this->configure($input)->flowEndpoint(), "input: {$input}");
        }
    }

    public function test_the_balance_endpoint_sits_at_the_api_root_not_under_flow(): void
    {
        $this->assertSame(
            'https://control.msg91.com/api/balance.php',
            $this->configure('https://control.msg91.com/api/v5/flow')->balanceEndpoint()
        );
    }

    /** MSG91 answers 200 for logical errors; that must not read as sent. */
    public function test_a_template_error_returned_with_http_200_is_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'type' => 'error',
            'message' => 'Template ID Missing or Invalid Template',
        ], 200)]);

        $result = $this->configure('https://control.msg91.com/api/v5')
            ->sendTemplate('9876543210', '6a76c9555ae4f51bdf041782', ['var1' => '123456']);

        $this->assertFalse($result['ok'], 'an MSG91 error must not be reported as sent');
        $this->assertStringContainsString('Template ID Missing or Invalid Template', $result['message']);
    }

    public function test_a_genuine_success_is_still_reported_as_sent(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'abc123'], 200)]);

        $result = $this->configure('https://control.msg91.com/api/v5')
            ->sendTemplate('9876543210', '6a76c9555ae4f51bdf041782', ['var1' => '123456']);

        $this->assertTrue($result['ok']);
    }

    public function test_the_send_posts_to_the_single_flow_path(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success'], 200)]);

        $this->configure('https://control.msg91.com/api/v5/flow')
            ->sendTemplate('9876543210', 'tpl', ['var1' => '1']);

        Http::assertSent(fn ($request) => $request->url() === 'https://control.msg91.com/api/v5/flow/');
    }
}
