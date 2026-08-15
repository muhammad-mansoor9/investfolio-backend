<?php

namespace App\Helpers;

class CacheHelper
{
    /**
     * Normalize cache data to array format
     * Handles both stdClass objects and arrays from cache serialization
     *
     * @param mixed $data
     * @return array
     */
    public static function toArray($data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            return (array) $data;
        }

        return [];
    }

    /**
     * Normalize collection of cached items
     *
     * @param mixed $items
     * @return \Illuminate\Support\Collection
     */
    public static function normalize($items)
    {
        return collect($items)->map(fn($item) => self::toArray($item));
    }
}
