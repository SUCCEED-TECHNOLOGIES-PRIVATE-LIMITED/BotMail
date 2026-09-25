<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('cloudflare_api_token')
                    ->label('Cloudflare API token')
                    ->password()
                    ->revealable()
                    ->helperText('Stored encrypted on your user. Leave blank to keep the current token. When empty, BotMail uses CLOUDFLARE_API_TOKEN from the environment.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (filled($data['cloudflare_api_token'] ?? null)) {
            auth()->user()?->update([
                'cloudflare_api_token' => $data['cloudflare_api_token'],
            ]);
        }

        $this->form->fill();

        Notification::make()->success()->title('Settings saved')->send();
    }
}
