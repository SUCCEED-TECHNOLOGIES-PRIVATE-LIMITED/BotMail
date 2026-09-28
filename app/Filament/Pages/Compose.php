<?php

namespace App\Filament\Pages;

use App\Models\Inbox;
use App\Services\OutboundMailService;
use App\Support\EmailAddress;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class Compose extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.compose';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('inbox_id')
                    ->label('Inbox')
                    ->options(fn (): array => Inbox::query()->orderBy('address')->pluck('address', 'id')->all())
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('to')
                    ->required()
                    ->helperText('Separate multiple addresses with commas.'),
                Forms\Components\TextInput::make('cc'),
                Forms\Components\TextInput::make('bcc'),
                Forms\Components\TextInput::make('subject')
                    ->required()
                    ->maxLength(998),
                Forms\Components\Textarea::make('body')
                    ->label('Message')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('attachments')
                    ->multiple()
                    ->storeFiles(false)
                    ->maxSize(10240),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $inbox = Inbox::query()->findOrFail($data['inbox_id']);

        try {
            app(OutboundMailService::class)->send($inbox, [
                'to' => EmailAddress::split($data['to'] ?? null),
                'cc' => EmailAddress::split($data['cc'] ?? null),
                'bcc' => EmailAddress::split($data['bcc'] ?? null),
                'subject' => $data['subject'],
                'html' => $data['body'],
                'text' => trim(html_entity_decode(strip_tags((string) $data['body']))),
                'attachments' => $this->encodeUploads($data['attachments'] ?? []),
            ]);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Send failed')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Message sent')->send();
        $this->form->fill();
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array<int, array{filename: string, mime: string, size: int, data: string}>
     */
    protected function encodeUploads(array $files): array
    {
        $attachments = [];

        foreach ($files as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $contents = file_get_contents($file->getRealPath());

            if ($contents === false) {
                continue;
            }

            $attachments[] = [
                'filename' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => strlen($contents),
                'data' => base64_encode($contents),
            ];
        }

        return $attachments;
    }
}
