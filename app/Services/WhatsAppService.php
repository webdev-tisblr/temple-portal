<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $apiUrl;
    private string $phoneNumberId;
    private string $accessToken;

    public function __construct()
    {
        $this->apiUrl = config('whatsapp.api_url');
        $this->phoneNumberId = config('whatsapp.phone_number_id', '');
        $this->accessToken = config('whatsapp.access_token', '');
    }

    public function sendTemplateMessage(string $phone, string $templateName, string $languageCode, array $components = []): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->send($payload);
    }

    public function sendTextMessage(string $phone, string $text): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ];

        return $this->send($payload);
    }

    public function sendDocument(string $phone, string $documentUrl, string $filename, string $caption = ''): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhone($phone),
            'type' => 'document',
            'document' => [
                'link' => $documentUrl,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->send($payload);
    }

    private function send(array $payload): bool
    {
        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            Log::warning('WhatsApp: credentials not configured, skipping message', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
            ]);
            return false;
        }

        $url = "{$this->apiUrl}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('WhatsApp message sent', [
                    'to' => $payload['to'],
                    'type' => $payload['type'],
                    'message_id' => $response->json('messages.0.id'),
                ]);
                return true;
            }

            Log::error('WhatsApp API error', [
                'to' => $payload['to'],
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', [
                'to' => $payload['to'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 10) {
            return '91' . $phone;
        }

        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            return $phone;
        }

        return '91' . $phone;
    }
}
