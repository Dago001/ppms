<?php
/**
 * Lightweight File-Based Cache Engine for NIS Posting System
 * Provides high-performance caching for heavy database aggregations and dynamic counts.
 */
class CacheManager {
    private static $cacheDir = __DIR__ . '/../storage/cache/';

    /**
     * Initialize cache storage directory
     */
    private static function init() {
        if (!file_exists(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }

    /**
     * Get item from cache
     * 
     * @param string $key Unique cache key
     * @param int $ttl Lifetime in seconds (default 600s / 10 min)
     * @return mixed|null Cached value or null if expired/missing
     */
    public static function get($key, $ttl = 600) {
        self::init();
        $file = self::$cacheDir . md5($key) . '.cache';

        if (!file_exists($file)) {
            return null;
        }

        $modified = @filemtime($file);
        if ($modified === false || (time() - $modified) > $ttl) {
            @unlink($file);
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return $data !== null ? $data : null;
    }

    /**
     * Store item in cache
     * 
     * @param string $key Unique cache key
     * @param mixed $data Data to store
     * @return bool Success status
     */
    public static function set($key, $data) {
        self::init();
        $file = self::$cacheDir . md5($key) . '.cache';
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        return @file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    /**
     * Delete specific cache entry
     * 
     * @param string $key Cache key
     */
    public static function delete($key) {
        self::init();
        $file = self::$cacheDir . md5($key) . '.cache';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Flush all cache files
     */
    public static function flush() {
        self::init();
        $files = glob(self::$cacheDir . '*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
