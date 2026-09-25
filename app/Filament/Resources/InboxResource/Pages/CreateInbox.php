<?php

namespace App\Filament\Resources\InboxResource\Pages;

use App\Exceptions\CloudflareApiException;
use App\Exceptions\InboxLimitException;
use App\Filament\Resources\InboxResource;
use App\Services\InboxService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInbox extends CreateRecord
{
    protected static string $resource = InboxResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['user_id'] = auth()->id();

        try {
            return app(InboxService::class)->create($data);
        } catch (InboxLimitException|CloudflareApiException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt();
        }
    }
}
