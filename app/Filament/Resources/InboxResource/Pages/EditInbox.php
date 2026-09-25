<?php

namespace App\Filament\Resources\InboxResource\Pages;

use App\Enums\InboxStatus;
use App\Exceptions\CloudflareApiException;
use App\Filament\Resources\InboxResource;
use App\Models\Inbox;
use App\Services\InboxService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInbox extends EditRecord
{
    protected static string $resource = InboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->using(function (Inbox $record): void {
                    app(InboxService::class)->delete($record);
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Inbox $record */
        $service = app(InboxService::class);
        $next = InboxStatus::from($data['status']);

        try {
            if ($next === InboxStatus::Paused && $record->status !== InboxStatus::Paused) {
                $service->pause($record);
            }

            if ($next === InboxStatus::Active && $record->status !== InboxStatus::Active) {
                $service->resume($record);
            }
        } catch (CloudflareApiException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt();
        }

        $record->update([
            'display_name' => $data['display_name'] ?? null,
        ]);

        return $record->fresh();
    }
}
