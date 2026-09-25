<div class="space-y-4">
    @foreach ($getRecord()->threadMessages() as $message)
        <article class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <header class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <div>
                    <span class="font-medium">{{ $message->from_address }}</span>
                    <span class="text-gray-500">to {{ $message->to_address }}</span>
                </div>
                <time class="text-gray-500">{{ $message->created_at?->toDayDateTimeString() }}</time>
            </header>

            <h3 class="mb-2 font-semibold">{{ $message->subject }}</h3>

            @if ($message->safeBodyHtml() !== '')
                <div class="prose max-w-none dark:prose-invert">{!! $message->safeBodyHtml() !!}</div>
            @else
                <div class="whitespace-pre-wrap">{{ $message->body_text }}</div>
            @endif

            @if ($message->attachmentFiles() !== [])
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($message->attachmentFiles() as $file)
                        <li>
                            <a
                                class="text-primary-600 underline"
                                href="{{ route('filament.admin.messages.attachments.download', ['message' => $message, 'index' => $file['index']]) }}"
                            >
                                {{ $file['filename'] }}
                                @if ($file['size'])
                                    ({{ number_format($file['size'] / 1024, 1) }} KB)
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>
    @endforeach
</div>
