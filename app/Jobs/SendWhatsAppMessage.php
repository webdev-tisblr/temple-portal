<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $phone,
        public string $type,
        public array $params,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $success = match ($this->type) {
            'template' => $whatsAppService->sendTemplateMessage(
                $this->phone,
                $this->params['template_name'],
                $this->params['language_code'] ?? 'en',
                $this->params['components'] ?? [],
            ),
            'text' => $whatsAppService->sendTextMessage(
                $this->phone,
                $this->params['text'],
            ),
            'document' => $whatsAppService->sendDocument(
                $this->phone,
                $this->params['url'],
                $this->params['filename'],
                $this->params['caption'] ?? '',
            ),
            default => false,
        };

        if (!$success) {
            Log::warning("WhatsApp message failed", [
                'phone' => $this->phone,
                'type' => $this->type,
                'attempt' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                Log::error("WhatsApp message permanently failed after {$this->tries} attempts", [
                    'phone' => $this->phone,
                    'type' => $this->type,
                ]);
                return;
            }

            $this->release($this->backoff);
        }
    }
}
