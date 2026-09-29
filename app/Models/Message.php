<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class Message extends Model
{
    use HasFactory;

    /**
     * Columns safe to load in lists. Bodies and attachment bytes stay off the wire.
     *
     * @var array<int, string>
     */
    public const LIST_COLUMNS = [
        'id',
        'inbox_id',
        'direction',
        'from_address',
        'to_address',
        'cc',
        'bcc',
        'subject',
        'thread_id',
        'in_reply_to',
        'is_read',
        'resend_id',
        'status',
        'is_spam',
        'message_id',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'inbox_id',
        'direction',
        'from_address',
        'to_address',
        'cc',
        'bcc',
        'subject',
        'body_text',
        'body_html',
        'headers',
        'attachments',
        'thread_id',
        'in_reply_to',
        'references',
        'is_read',
        'resend_id',
        'status',
        'is_spam',
        'message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'status' => MessageStatus::class,
            'headers' => 'array',
            'attachments' => 'array',
            'is_read' => 'boolean',
            'is_spam' => 'boolean',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function scopeForList(Builder $query): Builder
    {
        return $query->select(self::LIST_COLUMNS);
    }

    /**
     * @return Collection<int, self>
     */
    public function threadMessages(): Collection
    {
        if ($this->thread_id === null) {
            return new Collection([$this]);
        }

        return static::query()
            ->where('inbox_id', $this->inbox_id)
            ->where('thread_id', $this->thread_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function safeBodyHtml(): string
    {
        if (! is_string($this->body_html) || $this->body_html === '') {
            return '';
        }

        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks(false)
                ->allowRelativeMedias(false),
        );

        return $sanitizer->sanitize($this->body_html);
    }

    /**
     * @return array<int, array{index: int, filename: string, mime: string, size: int|null}>
     */
    public function attachmentFiles(): array
    {
        return collect($this->attachments ?? [])
            ->map(function (mixed $file, int $index): array {
                $file = is_array($file) ? $file : [];

                return [
                    'index' => $index,
                    'filename' => is_string($file['filename'] ?? null) ? $file['filename'] : 'attachment',
                    'mime' => is_string($file['mime'] ?? null) ? $file['mime'] : 'application/octet-stream',
                    'size' => isset($file['size']) ? (int) $file['size'] : null,
                ];
            })
            ->all();
    }
}
