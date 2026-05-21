<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<int|string|null, int|string|null>
 */
class UnsignedBigInteger implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|string|null
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $this->fitsNativeInteger($value) ? (int) $value : $value;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException($key.' must be an unsigned integer.');
        }

        $value = (string) $value;

        if (! ctype_digit($value)) {
            throw new InvalidArgumentException($key.' must be an unsigned integer.');
        }

        return [$key => $this->fitsNativeInteger($value) ? (int) $value : $value];
    }

    private function fitsNativeInteger(string $value): bool
    {
        return strlen($value) < strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && $value <= (string) PHP_INT_MAX);
    }
}
