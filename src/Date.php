<?php namespace Rackage;

/**
 * Date Helper Class
 * 
 * Provides common date and time manipulation utilities.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Date
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */
class Date {
    
    /**
     * Private constructor to prevent creating instances
     */
    private function __construct() {}
    
    /**
     * Private clone to prevent cloning
     */
    private function __clone() {}
    
    /**
     * Get current datetime in specified format
     * 
     * @param string $format Date format (default: Y-m-d H:i:s)
     * @return string Formatted current datetime
     */
    public static function now($format = 'Y-m-d H:i:s') {
        return date($format);
    }
    
    /**
     * Format a date string
     * 
     * @param string $date Date string or timestamp
     * @param string $format Desired output format
     * @return string Formatted date
     */
    public static function format($date, $format = 'Y-m-d H:i:s') {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        return date($format, $timestamp);
    }
    
    /**
     * Get human-readable time difference (e.g., "2 hours ago")
     * 
     * @param string $date Date string or timestamp
     * @return string Human-readable time difference
     */
    public static function ago($date) {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Add days to a date
     * 
     * @param string $date Date string or timestamp
     * @param int $days Number of days to add
     * @param string $format Output format
     * @return string New date
     */
    public static function add($date, $days, $format = 'Y-m-d H:i:s') {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $newTimestamp = strtotime("+{$days} days", $timestamp);
        return date($format, $newTimestamp);
    }
    
    /**
     * Subtract days from a date
     * 
     * @param string $date Date string or timestamp
     * @param int $days Number of days to subtract
     * @param string $format Output format
     * @return string New date
     */
    public static function subtract($date, $days, $format = 'Y-m-d H:i:s') {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $newTimestamp = strtotime("-{$days} days", $timestamp);
        return date($format, $newTimestamp);
    }
    
    /**
     * Calculate difference between two dates in days
     * 
     * @param string $date1 First date
     * @param string $date2 Second date
     * @return int Number of days difference
     */
    public static function diff($date1, $date2) {
        $timestamp1 = is_numeric($date1) ? $date1 : strtotime($date1);
        $timestamp2 = is_numeric($date2) ? $date2 : strtotime($date2);
        
        $diff = abs($timestamp1 - $timestamp2);
        return floor($diff / 86400);
    }
    
    /**
     * Check if date is a weekend
     * 
     * @param string $date Date string or timestamp
     * @return bool True if weekend (Saturday or Sunday)
     */
    public static function weekend($date) {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $dayOfWeek = date('N', $timestamp);
        return $dayOfWeek >= 6;
    }
    
    /**
     * Parse a date string to timestamp
     * 
     * @param string $date Date string
     * @return int Unix timestamp
     */
    public static function parse($date) {
        return strtotime($date);
    }
}