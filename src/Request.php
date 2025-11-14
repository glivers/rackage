<?php namespace Rackage;

/**
 * Request Helper Class
 * 
 * Provides access to HTTP request metadata.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Helpers
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */
class Request {
    
    /**
     * Private constructor to prevent creating instances
     */
    private function __construct() {}
    
    /**
     * Private clone to prevent cloning
     */
    private function __clone() {}
    
    /**
     * Get the request method
     * 
     * @return string The HTTP request method (GET, POST, etc.)
     */
    public static function method() {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    /**
     * Check if the request is AJAX
     * 
     * @return bool True if AJAX request
     */
    public static function ajax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Get the User Agent string
     * 
     * @return string The user agent string
     */
    public static function agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }
    
    /**
     * Check if the request path matches a pattern
     * 
     * @param string $pattern Pattern to match (supports * wildcard)
     * @return bool True if matches
     */
    public static function is($pattern) {
        $path = self::path();
        $pattern = str_replace('*', '.*', $pattern);
        $pattern = '#^' . $pattern . '$#';
        
        return (bool) preg_match($pattern, $path);
    }
    
    /**
     * Get the request path (without query string)
     * 
     * @return string The request path
     */
    public static function path() {
        $path = $_SERVER['REQUEST_URI'];
        
        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }
        
        return $path;
    }
    
    /**
     * Get the full URL (with query string)
     * 
     * @return string The full URL
     */
    public static function url() {
        $protocol = self::secure() ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Check if the request is over HTTPS
     * 
     * @return bool True if HTTPS
     */
    public static function secure() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $_SERVER['SERVER_PORT'] == 443;
    }
    
    /**
     * Get the client's IP address
     * 
     * @return string The client IP address
     */
    public static function ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    /**
     * Get a request header value
     * 
     * @param string $name The header name
     * @return mixed The header value or false if not found
     */
    public static function header($name) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        
        // Special cases without HTTP_ prefix
        $key = strtoupper(str_replace('-', '_', $name));
        if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            $serverKey = $key;
        }
        
        return isset($_SERVER[$serverKey]) ? $_SERVER[$serverKey] : false;
    }
    
    /**
     * Get the HTTP referer
     * 
     * @return mixed The referer URL or false if not set
     */
    public static function referer() {
        return isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false;
    }
}