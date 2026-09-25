<?php

namespace App\Support;

class EmailAddress
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return strtolower(trim($value));
    }

    /**
     * @param  array<int, mixed>|string|null  $value
     * @return array<int, string>
     */
    public static function split(array|string|null $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): ?string => is_string($item) ? self::normalize($item) : null,
                $value,
            )));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $value) ?: [];

        return array_values(array_filter(array_map(
            fn (string $item): ?string => self::normalize($item),
            $parts,
        )));
    }

    /**
     * @param  array<int, string>  $addresses
     */
    public static function join(array $addresses): ?string
    {
        $addresses = array_values(array_filter($addresses));

        return $addresses === [] ? null : implode(', ', $addresses);
    }
}
