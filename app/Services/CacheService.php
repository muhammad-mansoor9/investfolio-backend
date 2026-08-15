<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    const CACHE_TTL_DAILY = 3 * 24 * 60 * 60; // 3 days for daily data
    const CACHE_TTL_QUARTERLY = 90 * 24 * 60 * 60; // 90 days for quarterly data
    const CACHE_TTL_STATIC = 7 * 24 * 60 * 60; // 7 days for static data

    /**
     * Get or cache data with automatic expiry (TTL)
     */
    public static function remember($key, $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Get from cache without TTL (immutable data)
     */
    public static function rememberForever($key, callable $callback)
    {
        return Cache::rememberForever($key, $callback);
    }

    /**
     * Put data in cache with TTL
     */
    public static function put($key, $data, $ttl = null)
    {
        return Cache::put($key, $data, $ttl);
    }

    /**
     * Put data in cache forever (no TTL)
     */
    public static function putForever($key, $data)
    {
        return Cache::put($key, $data, null);
    }

    /**
     * Get from cache
     */
    public static function get($key)
    {
        return Cache::get($key);
    }

    /**
     * Forget a key
     */
    public static function forget($key)
    {
        return Cache::forget($key);
    }

    /**
     * Clear multiple keys by pattern (for daily data)
     */
    public static function forgetByDate($date)
    {
        // For daily data, we typically want to clear keys with the date suffix
        // This is handled per service
        return true;
    }
}
