<?php

namespace App\Filament\Resources;

use App\Enums\InboxStatus;
use App\Exceptions\CloudflareApiException;
use App\Filament\Resources\InboxResource\Pages;
use App\Models\Inbox;
use App\Services\InboxService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InboxResource extends Resource
{
    protected static ?string $model = Inbox::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('local_part')
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9][a-z0-9._+-]*$/')
                    ->disabledOn('edit')
                    ->dehydrated(),
                Forms\Components\Select::make('domain_id')
                    ->relationship('domain', 'domain')
                    ->required()
                    ->disabledOn('edit')
                    ->dehydrated(),
                Forms\Components\TextInput::make('display_name')
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->options([
                        InboxStatus::Active->value => 'Active',
                        InboxStatus::Paused->value => 'Paused',
                    ])
                    ->default(InboxStatus::Active->value)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain.domain')
                    ->label('Domain')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (InboxStatus $state): string => $state === InboxStatus::Active ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('Messages'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('pause')
                    ->icon('heroicon-o-pause')
                    ->visible(fn (Inbox $record): bool => $record->status === InboxStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (Inbox $record): void {
                        try {
                            app(InboxService::class)->pause($record);
                            Notification::make()->success()->title('Inbox paused')->send();
                        } catch (CloudflareApiException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('resume')
                    ->icon('heroicon-o-play')
                    ->visible(fn (Inbox $record): bool => $record->status === InboxStatus::Paused)
                    ->requiresConfirmation()
                    ->action(function (Inbox $record): void {
                        try {
                            app(InboxService::class)->resume($record);
                            Notification::make()->success()->title('Inbox resumed')->send();
                        } catch (CloudflareApiException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->using(function (Inbox $record): void {
                        try {
                            app(InboxService::class)->delete($record);
                        } catch (CloudflareApiException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                            throw $exception;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            $records->each(function (Inbox $inbox): void {
                                app(InboxService::class)->delete($inbox);
                            });
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('domain');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboxes::route('/'),
            'create' => Pages\CreateInbox::route('/create'),
            'edit' => Pages\EditInbox::route('/{record}/edit'),
        ];
    }
}
