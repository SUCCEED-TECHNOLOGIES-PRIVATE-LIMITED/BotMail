<?php

namespace App\Filament\Resources;

use App\Enums\MessageDirection;
use App\Filament\Resources\MessageResource\Pages;
use App\Models\Message;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('from_address')->label('From'),
                TextEntry::make('to_address')->label('To'),
                TextEntry::make('subject')->columnSpanFull(),
                ViewEntry::make('thread')
                    ->view('filament.messages.thread')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select(Message::LIST_COLUMNS))
            ->columns([
                Tables\Columns\TextColumn::make('from_address')
                    ->label('From')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject')
                    ->searchable()
                    ->limit(80),
                Tables\Columns\TextColumn::make('inbox.address')
                    ->label('Inbox'),
                Tables\Columns\TextColumn::make('direction')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_read')
                    ->label('Read')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('inbox_id')
                    ->label('Inbox')
                    ->relationship('inbox', 'address'),
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        MessageDirection::Inbound->value => 'Inbound',
                        MessageDirection::Outbound->value => 'Outbound',
                    ]),
                Tables\Filters\TernaryFilter::make('is_read')
                    ->label('Read')
                    ->trueLabel('Read')
                    ->falseLabel('Unread'),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('markRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-envelope-open')
                    ->visible(fn (Message $record): bool => ! $record->is_read)
                    ->action(fn (Message $record) => $record->update(['is_read' => true])),
                Tables\Actions\Action::make('markUnread')
                    ->label('Mark unread')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Message $record): bool => $record->is_read)
                    ->action(fn (Message $record) => $record->update(['is_read' => false])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
            'view' => Pages\ViewMessage::route('/{record}'),
        ];
    }
}
