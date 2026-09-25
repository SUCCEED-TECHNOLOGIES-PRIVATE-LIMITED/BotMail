<?php

namespace App\Filament\Resources;

use App\Exceptions\CloudflareApiException;
use App\Filament\Resources\DomainResource\Pages;
use App\Models\Domain;
use App\Services\CloudflareEmailService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('domain')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->rule('regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'),
                Forms\Components\TextInput::make('cloudflare_zone_id')
                    ->label('Cloudflare zone ID')
                    ->maxLength(64)
                    ->helperText('Leave blank to look the zone up by domain name.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cloudflare_zone_id')
                    ->label('Zone ID')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('verified_at')
                    ->dateTime()
                    ->placeholder('Not verified'),
                Tables\Columns\TextColumn::make('inboxes_count')
                    ->counts('inboxes')
                    ->label('Inboxes'),
            ])
            ->defaultSort('domain')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('verify')
                    ->icon('heroicon-o-check-badge')
                    ->action(function (Domain $record): void {
                        try {
                            app(CloudflareEmailService::class)->verifyDomain($record);
                            Notification::make()->success()->title('Domain verified')->send();
                        } catch (CloudflareApiException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Domain $record): void {
                        if ($record->inboxes()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('Delete the inboxes on this domain first.')
                                ->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Collection $records): void {
                            if ($records->contains(fn (Domain $domain): bool => $domain->inboxes()->exists())) {
                                Notification::make()
                                    ->danger()
                                    ->title('Delete the inboxes on these domains first.')
                                    ->send();

                                throw new Halt;
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'create' => Pages\CreateDomain::route('/create'),
            'edit' => Pages\EditDomain::route('/{record}/edit'),
        ];
    }
}
