<?php namespace Rackage;

/**
 * Cache Helper Class
 * 
 * Provides file-based caching with expiration support for storing 
 * expensive operations, API responses, and query results.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Cache
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\Path;

class Cache {
    
    /**
     * Cache directory path
     * 
     * @var string
     */
    private static $cacheDir = null;
    
    /**
     * Private constructor to prevent creating instances
     * 
     * @param null
     * @return void
     */
    private function __construct() {}
    
    /**
     * Private clone to prevent cloning
     * 
     * @param null
     * @return void
     */
    private function __clone() {}
    
    /**
     * Get cache directory path
     * 
     * @param null
     * @return string Cache directory path
     */
    private static function getCacheDir() {
        if (self::$cacheDir === null) {
            self::$cacheDir = Path::tmp() . 'cache' . DIRECTORY_SEPARATOR;
            
            // Create cache directory if it doesn't exist
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }
        }
        
        return self::$cacheDir;
    }
    
    /**
     * Get file path for cache key
     * 
     * @param string $key Cache key
     * @return string Full file path
     */
    private static function getFilePath($key) {
        return self::getCacheDir() . md5($key) . '.cache';
    }
    
    /**
     * Get cached value
     * 
     * Retrieves value from cache if it exists and hasn't expired.
     * 
     * @param string $key Cache key
     * @return mixed Cached value or false if not found or expired
     */
    public static function get($key) {
        $file = self::getFilePath($key);
        
        // Check if cache file exists
        if (!file_exists($file)) {
            return false;
        }
        
        // Read cache file
        $content = file_get_contents($file);
        $data = unserialize($content);
        
        // Check if data is valid
        if (!is_array($data) || !isset($data['expires']) || !isset($data['value'])) {
            self::delete($key);
            return false;
        }
        
        // Check if cache has expired
        if ($data['expires'] < time()) {
            self::delete($key);
            return false;
        }
        
        // Return cached value
        return $data['value'];
    }
    
    /**
     * Store value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $minutes Cache duration in minutes (default: 60)
     * @return bool True on success, false on failure
     */
    public static function set($key, $value, $minutes = 60) {
        $file = self::getFilePath($key);
        
        // Prepare cache data
        $data = [
            'value' => $value,
            'expires' => time() + ($minutes * 60),
            'created' => time()
        ];
        
        // Write to cache file
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    /**
     * Check if cache key exists and is valid
     * 
     * @param string $key Cache key
     * @return bool True if exists and not expired
     */
    public static function has($key) {
        return self::get($key) !== false;
    }
    
    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool True on success, false if file doesn't exist
     */
    public static function delete($key) {
        $file = self::getFilePath($key);
        
        if (file_exists($file)) {
            return unlink($file);
        }
        
        return false;
    }
    
    /**
     * Clear all cached values
     * 
     * Removes all cache files from the cache directory.
     * 
     * @param null
     * @return bool True on success
     */
    public static function flush() {
        $cacheDir = self::getCacheDir();
        $files = glob($cacheDir . '*.cache');
        
        if (empty($files)) {
            return true;
        }
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }
    
    /**
     * Get or set cached value using callback
     * 
     * Retrieves cached value if exists, otherwise executes callback,
     * caches the result, and returns it.
     * 
     * @param string $key Cache key
     * @param int $minutes Cache duration in minutes
     * @param callable $callback Function to execute if cache misses
     * @return mixed Cached or fresh value
     */
    public static function remember($key, $minutes, $callback) {
        // Try to get from cache
        $value = self::get($key);
        
        // Return cached value if found
        if ($value !== false) {
            return $value;
        }
        
        // Execute callback to get fresh value
        $value = $callback();
        
        // Store in cache
        self::set($key, $value, $minutes);
        
        // Return fresh value
        return $value;
    }
}