<?php namespace Rackage;

/**
 * HTML Helper Class
 * 
 * Provides HTML escaping to prevent XSS attacks.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */
class HTML {
    
    private function __construct() {}
    private function __clone() {}
    
    /**
     * Escape HTML special characters to prevent XSS
     * 
     * @param string $string String to escape
     * @return string Escaped string
     */
    public static function escape($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}