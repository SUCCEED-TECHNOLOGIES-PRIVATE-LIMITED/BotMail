<?php

namespace App\Filament\Widgets;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Inbox;
use App\Models\Message;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BotMailStats extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $outbound = Message::query()->where('direction', MessageDirection::Outbound);
        $attempts = (clone $outbound)->count();
        $successful = (clone $outbound)
            ->whereIn('status', [MessageStatus::Sent, MessageStatus::Delivered])
            ->count();

        $rate = $attempts === 0
            ? '—'
            : round(($successful / $attempts) * 100, 1).'%';

        return [
            Stat::make('Total inboxes', (string) Inbox::query()->count()),
            Stat::make('Messages today', (string) Message::query()->whereDate('created_at', today())->count()),
            Stat::make('Unread', (string) Message::query()
                ->where('direction', MessageDirection::Inbound)
                ->where('is_read', false)
                ->count()),
            Stat::make('Send success rate', $rate),
        ];
    }
}
