<?php
// includes/performance.php
// Performance Optimization Layer for NIS-PPMS

// ============================================
// 1. OUTPUT COMPRESSION
// ============================================
function startOutputCompression() {
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        // Enable GZIP compression if not already enabled
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
            ob_start('ob_gzhandler');
        } else {
            ob_start();
        }
    }
}

// ============================================
// 2. MINIFY HTML OUTPUT
// ============================================
function minifyHTML($html) {
    // Only minify if not in debug mode
    if (defined('DEBUG_MODE') && DEBUG_MODE) return $html;
    
    // Remove HTML comments (except conditional comments)
    $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
    
    // Remove whitespace between tags
    $html = preg_replace('/>\s+</', '><', $html);
    
    // Remove multiple spaces
    $html = preg_replace('/\s{2,}/', ' ', $html);
    
    // Remove spaces around inline elements
    $html = preg_replace('/\s*<\/(span|strong|em|a|b|i|u|small|code|sup|sub)\s*>\s*/i', '</$1>', $html);
    $html = preg_replace('/\s*<\s*(span|strong|em|a|b|i|u|small|code|sup|sub)\s*>/i', '<$1>', $html);
    
    return trim($html);
}

// ============================================
// 3. DATABASE QUERY CACHE (Simple File-Based)
// ============================================
class SimpleCache {
    private $cacheDir;
    private $defaultTTL = 300; // 5 minutes
    
    public function __construct() {
        $this->cacheDir = __DIR__ . '/../storage/cache/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    public function get($key) {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) return null;
        
        $data = json_decode(file_get_contents($file), true);
        if (!$data) return null;
        
        if (time() > $data['expires']) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?: $this->defaultTTL;
        $file = $this->getCacheFile($key);
        
        $data = [
            'expires' => time() + $ttl,
            'value' => $value
        ];
        
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
    
    public function delete($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) unlink($file);
    }
    
    public function clear() {
        $files = glob($this->cacheDir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
    }
    
    private function getCacheFile($key) {
        return $this->cacheDir . md5($key) . '.cache';
    }
}

// ============================================
// 4. DATABASE CONNECTION POOLING
// ============================================
function getOptimizedPDO($config) {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::ATTR_PERSISTENT => true, // Persistent connections
        PDO::ATTR_TIMEOUT => 5
    ];
    
    return new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        $options
    );
}

// ============================================
// 5. LAZY LOADING FOR IMAGES
// ============================================
function lazyImage($src, $alt = '', $class = '', $width = '', $height = '') {
    $attrs = '';
    if ($class) $attrs .= ' class="' . $class . '"';
    if ($width) $attrs .= ' width="' . $width . '"';
    if ($height) $attrs .= ' height="' . $height . '"';
    
    return sprintf(
        '<img src="data:image/svg+xml,%%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 %s %s\'%%3E%%3C/svg%%3E" 
              data-src="%s" 
              alt="%s" 
              loading="lazy" 
              %s
              onerror="this.style.display=\'none\'">',
        $width ?: '1',
        $height ?: '1',
        htmlspecialchars($src),
        htmlspecialchars($alt),
        $attrs
    );
}

// ============================================
// 6. ASSET PRELOADING
// ============================================
function preloadAssets() {
    $preload = [
        '<link rel="preconnect" href="https://cdn.jsdelivr.net">',
        '<link rel="preconnect" href="https://cdnjs.cloudflare.com">',
        '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">',
        '<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">',
        '<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" as="style">',
        '<link rel="preload" href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js" as="script">'
    ];
    
    return implode("\n    ", $preload);
}

// ============================================
// 7. DATABASE INDEX RECOMMENDATIONS
// ============================================
// Ensure these indexes exist for optimal performance:
/*
ALTER TABLE `tbl_emppersonal` ADD INDEX `idx_serviceNo` (`serviceNo`);
ALTER TABLE `tbl_employment` ADD INDEX `idx_serviceNo` (`serviceNo`);
ALTER TABLE `tbl_employment` ADD INDEX `idx_presentPosting` (`presentPosting`);
ALTER TABLE `tbl_employment` ADD INDEX `idx_currentRank` (`currentRank`);
ALTER TABLE `tbl_employment` ADD INDEX `idx_empStatus` (`empStatus`);
ALTER TABLE `posting_notifications` ADD INDEX `idx_status_created` (`status`, `created_at`);
ALTER TABLE `posting_notifications` ADD INDEX `idx_is_read` (`is_read`);
ALTER TABLE `posting_notifications` ADD INDEX `idx_serviceNo` (`serviceNo`);
ALTER TABLE `posting_history` ADD INDEX `idx_serviceNo_date` (`serviceNo`, `posting_date`);
ALTER TABLE `current_posting` ADD INDEX `idx_serviceNo` (`serviceNo`);
ALTER TABLE `users` ADD INDEX `idx_status` (`status`);
ALTER TABLE `user_zones` ADD INDEX `idx_user_id` (`user_id`);
*/