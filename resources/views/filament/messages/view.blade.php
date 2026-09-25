<x-filament-panels::page>
    @if ($this->hasInfolist())
        {{ $this->infolist }}
    @endif

    <x-filament::section heading="Reply" class="mt-6">
        <form wire:submit="sendReply" class="space-y-4">
            {{ $this->replyForm }}

            <x-filament::button type="submit">
                Send reply
            </x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
