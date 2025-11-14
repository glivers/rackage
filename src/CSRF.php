<?php namespace Rackage;

/**
 * CSRF Protection Helper Class
 * 
 * Provides Cross-Site Request Forgery protection through token generation and validation.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\CSRF
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\Session\Session;

class CSRF {
    
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
     * Generate and retrieve CSRF token
     * 
     * Creates a new token if one doesn't exist in the session.
     * 
     * @param null
     * @return string The CSRF token
     */
    public static function token() {
        // Check if token exists in session
        if (!Session::has('csrf_token')) {
            // Generate cryptographically secure random token
            $token = bin2hex(random_bytes(32));
            
            // Store token in session
            Session::set('csrf_token', $token);
        }
        
        // Return the token
        return Session::get('csrf_token');
    }
    
    /**
     * Get CSRF token as hidden form field
     * 
     * Returns HTML string with hidden input field containing CSRF token.
     * 
     * @param null
     * @return string HTML hidden input field
     */
    public static function field() {
        // Generate hidden input field with token
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }
    
    /**
     * Validate CSRF token
     * 
     * Compares provided token with session token using timing-safe comparison.
     * 
     * @param string $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public static function valid($token) {
        // Get token from session
        $sessionToken = Session::get('csrf_token');
        
        // Validate token exists and matches (timing-safe comparison)
        return $sessionToken && hash_equals($sessionToken, $token);
    }
}