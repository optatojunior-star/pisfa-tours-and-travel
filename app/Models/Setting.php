<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use JsonException;

class Setting extends Model
{
    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_FLOAT = 'float';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

    public const TYPE_NULL = 'null';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting === null ? $default : $setting->typedValue();
    }

    /**
     * @param  array{group?: string, description?: ?string, is_public?: bool}  $attributes
     */
    public static function setValue(string $key, mixed $value, array $attributes = []): static
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A setting key cannot be empty.');
        }

        $type = static::typeFor($value);
        $setting = static::query()->firstOrNew(['key' => $key]);
        $setting->fill(Arr::only($attributes, ['group', 'description', 'is_public']));
        $setting->type = $type;
        $setting->value = static::encode($value, $type);
        $setting->save();

        return $setting;
    }

    public function typedValue(): mixed
    {
        return match ($this->type) {
            self::TYPE_NULL => null,
            self::TYPE_BOOLEAN => $this->value === '1',
            self::TYPE_INTEGER => (int) $this->value,
            self::TYPE_FLOAT => (float) $this->value,
            self::TYPE_JSON => $this->decodeJson(),
            self::TYPE_STRING => (string) $this->value,
            default => throw new InvalidArgumentException("Unsupported setting type [{$this->type}]."),
        };
    }

    protected static function typeFor(mixed $value): string
    {
        return match (true) {
            $value === null => self::TYPE_NULL,
            is_bool($value) => self::TYPE_BOOLEAN,
            is_int($value) => self::TYPE_INTEGER,
            is_float($value) => self::TYPE_FLOAT,
            is_string($value) => self::TYPE_STRING,
            is_array($value) => self::TYPE_JSON,
            default => throw new InvalidArgumentException(
                'Settings support only null, boolean, integer, float, string, and array values.'
            ),
        };
    }

    protected static function encode(mixed $value, string $type): ?string
    {
        return match ($type) {
            self::TYPE_NULL => null,
            self::TYPE_BOOLEAN => $value ? '1' : '0',
            self::TYPE_JSON => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    protected function decodeJson(): mixed
    {
        try {
            return json_decode((string) $this->value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Setting [{$this->key}] contains invalid JSON.",
                previous: $exception,
            );
        }
    }
}
