<?php

namespace App\Filament\Resources\MessageResource\Pages;

use App\Enums\MessageDirection;
use App\Filament\Resources\MessageResource;
use App\Models\Message;
use App\Services\OutboundMailService;
use App\Support\EmailAddress;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewMessage extends ViewRecord
{
    protected static string $resource = MessageResource::class;

    protected static string $view = 'filament.messages.view';

    public ?array $replyData = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $message = $this->getRecord();
        $to = $message->direction === MessageDirection::Inbound
            ? $message->from_address
            : EmailAddress::split($message->to_address)[0] ?? $message->to_address;

        $this->replyForm->fill([
            'to' => $to,
        ]);
    }

    public function replyForm(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('to')
                    ->email()
                    ->required(),
                Forms\Components\TextInput::make('cc'),
                Forms\Components\TextInput::make('bcc'),
                Forms\Components\Textarea::make('html')
                    ->label('Reply')
                    ->required()
                    ->rows(8),
            ])
            ->statePath('replyData');
    }

    public function sendReply(): void
    {
        $data = $this->replyForm->getState();
        /** @var Message $message */
        $message = $this->getRecord();

        try {
            app(OutboundMailService::class)->reply($message->inbox, $message, [
                'to' => [$data['to']],
                'cc' => EmailAddress::split($data['cc'] ?? null),
                'bcc' => EmailAddress::split($data['bcc'] ?? null),
                'html' => $data['html'],
                'text' => trim(html_entity_decode(strip_tags($data['html']))),
            ]);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Reply failed')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Reply sent')->send();
        $this->replyForm->fill(['to' => $data['to']]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markRead')
                ->label('Mark read')
                ->visible(fn (): bool => ! $this->getRecord()->is_read)
                ->action(fn () => $this->getRecord()->update(['is_read' => true])),
            Actions\Action::make('markUnread')
                ->label('Mark unread')
                ->visible(fn (): bool => (bool) $this->getRecord()->is_read)
                ->action(fn () => $this->getRecord()->update(['is_read' => false])),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @return array<int|string, Forms\Form>
     */
    protected function getForms(): array
    {
        return [
            'replyForm' => $this->replyForm($this->makeForm()),
        ];
    }
}
