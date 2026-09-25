<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Domain extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'domain',
        'cloudflare_zone_id',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Domain $domain): void {
            if ($domain->inboxes()->exists()) {
                throw new RuntimeException('Delete the inboxes on this domain first.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }
}
