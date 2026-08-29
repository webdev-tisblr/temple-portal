<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissionResource\Pages;

use App\Filament\Resources\ContactSubmissionResource;
use App\Models\ContactSubmission;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewContactSubmission extends ViewRecord
{
    protected static string $resource = ContactSubmissionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Mark as read when admin opens the submission.
        /** @var ContactSubmission $record */
        $record = $this->getRecord();
        if (!$record->is_read) {
            $record->update(['is_read' => true, 'read_at' => now()]);
        }
        // Opening the thread also clears the devotee's follow-ups from the
        // "waiting on us" count — the admin has now seen them.
        $record->messages()->unreadByAdmin()->update(['read_at' => now()]);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Same action object the table row uses, re-typed as a page
            // action so the two can never answer differently.
            Actions\Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->modalHeading(fn (): string => 'Reply to '.($this->getRecord()->name ?: 'devotee'))
                ->modalSubmitActionLabel('Send reply')
                ->visible(fn (): bool => $this->getRecord()->devotee_id !== null
                    && (auth('admin')->user()?->can('update_contact::submission') ?? false))
                ->form([
                    \Filament\Forms\Components\Textarea::make('body')
                        ->hiddenLabel()
                        ->placeholder('Write your reply…')
                        ->rows(6)
                        ->required()
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    /** @var ContactSubmission $record */
                    $record = $this->getRecord();

                    app(\App\Services\ContactThreadService::class)
                        ->replyAsAdmin($record, (string) $data['body'], auth('admin')->user());

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Reply sent')
                        ->body('It is now visible to the devotee in the app.')
                        ->send();

                    $this->refreshFormData(['thread']);
                }),
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
