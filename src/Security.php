<?php namespace Rackage;

/**
 * Security Helper Class
 * 
 * Provides security-related functionality including CSP and CORS headers.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */
class Security {
    
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
     * Set Content Security Policy headers
     * 
     * Helps prevent XSS attacks by controlling which resources can be loaded.
     * 
     * @param array $policy CSP policy directives
     * @return void
     */
    public static function csp($policy = []) {
        // Default secure policy
        $defaults = [
            'default-src' => "'self'",
            'script-src' => "'self'",
            'style-src' => "'self' 'unsafe-inline'",
            'img-src' => "'self' data: https:",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'frame-ancestors' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'"
        ];
        
        // Merge with custom policy
        $policy = array_merge($defaults, $policy);
        
        // Build CSP header string
        $cspParts = [];
        foreach ($policy as $directive => $value) {
            $cspParts[] = $directive . ' ' . $value;
        }
        
        $cspHeader = implode('; ', $cspParts);
        
        // Set header
        header("Content-Security-Policy: $cspHeader");
    }
    
    /**
     * Set CORS headers for cross-origin requests
     * 
     * @param array $allowedOrigins Array of allowed origin URLs
     * @param array $options Additional CORS options
     * @return void
     */
    public static function cors($allowedOrigins = [], $options = []) {
        // Get request origin
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        // Default options
        $defaults = [
            'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-CSRF-TOKEN, X-Requested-With',
            'credentials' => true,
            'max_age' => 86400
        ];
        
        $options = array_merge($defaults, $options);
        
        // Check if origin is allowed
        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            // Set allowed origin (use specific origin, not * if credentials are true)
            if ($options['credentials'] && $origin) {
                header("Access-Control-Allow-Origin: $origin");
                header('Access-Control-Allow-Credentials: true');
            } else {
                header('Access-Control-Allow-Origin: ' . (in_array('*', $allowedOrigins) ? '*' : $origin));
            }
            
            // Set allowed methods
            header('Access-Control-Allow-Methods: ' . $options['methods']);
            
            // Set allowed headers
            header('Access-Control-Allow-Headers: ' . $options['headers']);
            
            // Set max age for preflight cache
            header('Access-Control-Max-Age: ' . $options['max_age']);
            
            // Handle preflight OPTIONS request
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit;
            }
        }
    }
    
    /**
     * Set common security headers
     * 
     * Sets multiple security headers at once for basic protection.
     * 
     * @param null
     * @return void
     */
    public static function headers() {
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS filter in browsers
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Force HTTPS (only if already on HTTPS)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}