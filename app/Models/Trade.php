<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'initiator_id',
        'recipient_id',
        'guild_id',
        'status',
        'status_changed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Trade $trade): void {
            if (! $trade->exists && $trade->status_changed_at === null) {
                $trade->status_changed_at = now();
            }

            if ($trade->exists && $trade->isDirty('status') && ! $trade->isDirty('status_changed_at')) {
                $trade->status_changed_at = now();
            }
        });
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function tradeItems(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    public function escrowItems(): HasMany
    {
        return $this->hasMany(EscrowItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function involvesUser(User $user): bool
    {
        return (int) $this->initiator_id === (int) $user->id
            || (int) $this->recipient_id === (int) $user->id;
    }

    public function canBeAcceptedBy(User $user): bool
    {
        return $this->isPending() && (int) $this->recipient_id === (int) $user->id;
    }

    public function canBeCancelledBy(User $user): bool
    {
        return $this->isPending() && (int) $this->initiator_id === (int) $user->id;
    }
}
