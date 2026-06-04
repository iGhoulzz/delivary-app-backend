<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use Database\Factories\AccountModerationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @use HasFactory<AccountModerationActionFactory>
 */
final class AccountModerationAction extends Model
{
    /** @use HasFactory<AccountModerationActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // append-only — created_at only

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id', 'actor_id',
        'action', 'reason_code', 'detail',
        'from_status', 'to_status',
    ];

    protected static function booted(): void
    {
        self::creating(static function (AccountModerationAction $row): void {
            if (empty($row->public_id)) {
                $row->public_id = (string) Str::ulid();
            }
            if (empty($row->created_at)) {
                $row->created_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ModerationAction::class,
            'reason_code' => ModerationReason::class,
            'from_status' => AccountStatus::class,
            'to_status' => AccountStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
