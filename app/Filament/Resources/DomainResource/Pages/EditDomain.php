<?php

namespace App\Filament\Resources\DomainResource\Pages;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (): void {
                    /** @var Domain $record */
                    $record = $this->getRecord();

                    if ($record->inboxes()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('Delete the inboxes on this domain first.')
                            ->send();

                        throw new Halt;
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['domain']);

        return $data;
    }
}
