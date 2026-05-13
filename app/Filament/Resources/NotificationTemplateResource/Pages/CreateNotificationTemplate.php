<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\WhatsAppTemplateBlueprint;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationTemplate extends CreateRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    /**
     * Convert the flat auto-detected `wa_vars` form state into the
     * `wa_components` JSON shape the WhatsApp driver consumes, and
     * derive `placeholder_map` from the registry so the driver can
     * resolve every token to its dot-path. Both happen on save —
     * admins never see either column.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return EditNotificationTemplate::serialiseTemplate($data);
    }
}
