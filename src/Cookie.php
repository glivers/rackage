<?php namespace Rackage;

/**
 * Cookie Helper Class
 * 
 * Provides methods for setting, getting, and deleting cookies.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Helpers
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */
class Cookie {
    
    /**
     * Private constructor to prevent creating instances
     */
    private function __construct() {}
    
    /**
     * Private clone to prevent cloning
     */
    private function __clone() {}
    
    /**
     * Set a cookie
     * 
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $minutes Expiration time in minutes (0 = session cookie)
     * @param string $path Path where cookie is available
     * @param bool $secure Only send over HTTPS
     * @param bool $httpOnly Accessible only through HTTP protocol
     * @return bool True on success
     */
    public static function set($name, $value, $minutes = 0, $path = '/', $secure = false, $httpOnly = true) {
        $expiry = $minutes === 0 ? 0 : time() + ($minutes * 60);
        return setcookie($name, $value, $expiry, $path, '', $secure, $httpOnly);
    }
    
    /**
     * Get a cookie value
     * 
     * @param string $name Cookie name
     * @return mixed Cookie value or false if not found
     */
    public static function get($name) {
        return isset($_COOKIE[$name]) ? $_COOKIE[$name] : false;
    }
    
    /**
     * Check if cookie exists
     * 
     * @param string $name Cookie name
     * @return bool True if cookie exists
     */
    public static function has($name) {
        return isset($_COOKIE[$name]);
    }
    
    /**
     * Delete a cookie
     * 
     * @param string $name Cookie name
     * @param string $path Path where cookie is available
     * @return bool True on success
     */
    public static function delete($name, $path = '/') {
        if (self::has($name)) {
            unset($_COOKIE[$name]);
            return setcookie($name, '', time() - 3600, $path);
        }
        return false;
    }
}