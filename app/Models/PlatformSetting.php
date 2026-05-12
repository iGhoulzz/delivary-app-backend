<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class PlatformSetting extends Model
{
    private const string CACHE_PREFIX = 'platform_setting:';

    private const int CACHE_TTL_SECONDS = 3600;

    /** @var array<int, string> */
    protected $fillable = ['key', 'value', 'type', 'description', 'updated_by_admin_id'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL_SECONDS,
            static function () use ($key, $default): mixed {
                $setting = self::query()->where('key', $key)->first();

                return $setting?->castedValue() ?? $default;
            }
        );
    }

    public static function set(string $key, mixed $value, ?int $adminId = null): self
    {
        $setting = self::query()->firstOrNew(['key' => $key]);
        $setting->value = is_scalar($value) ? (string) $value : (string) json_encode($value);
        $setting->updated_by_admin_id = $adminId;
        $setting->save();

        Cache::forget(self::CACHE_PREFIX.$key);

        return $setting;
    }

    public function castedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }

    protected static function booted(): void
    {
        self::saved(static fn (self $s) => Cache::forget(self::CACHE_PREFIX.$s->key));
        self::deleted(static fn (self $s) => Cache::forget(self::CACHE_PREFIX.$s->key));
    }
}
