<?php namespace Rackage;

/**
 * Registry - Service Locator and Configuration Container
 *
 * This class implements the Singleton pattern to manage application-wide
 * resources and configuration. It stores object instances, configuration
 * settings, and provides lazy-loading of framework resources.
 *
 * Purpose:
 *   - Store singleton instances (database, cache, template)
 *   - Manage application configuration from config files
 *   - Provide centralized access to framework resources
 *   - Prevent duplicate object instantiation
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo 
 * @category Rackage
 * @package Rachie\Rackage
 */

use Rackage\Database\Database;
use Rackage\Cache\CacheBase;
use Rackage\Templates\Template;

class Registry {

	/**
	 * Application start time in microseconds
	 * Used for performance profiling and request execution time tracking
	 * 
	 * @var float
	 */
	public static $rachie_app_start;

	/**
	 * Stored object instances (singleton registry)
	 * Stores instantiated objects to avoid duplicate creation
	 * 
	 * Format: ['database' => DbInstance, 'cache' => CacheInstance, ...]
	 * 
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Application settings (from settings.php)
	 * Contains general application configuration
	 * 
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Database configuration (from database.php)
	 * Contains database connection settings for all drivers
	 * 
	 * @var array
	 */
	private static $database = array();

	/**
	 * Cache configuration (from cache.php)
	 * Contains cache driver settings
	 * 
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Mail configuration (from mail.php)
	 * Contains email/SMTP settings
	 * 
	 * @var array
	 */
	private static $mail = array();

	/**
	 * Current request URL string
	 * Stored for routing and URL generation throughout the request lifecycle
	 * 
	 * @var string
	 */
	private static $url = '';

	/**
	 * Registered framework resources
	 * List of resources that can be lazy-loaded via Registry::get()
	 * 
	 * @var array
	 */
	private static $resources = array(
		'database',
		'cache',
		'template',
	);

	/**
	 * Private constructor to prevent direct instantiation
	 * Enforces singleton pattern
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Private clone method to prevent object cloning
	 * Enforces singleton pattern
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Get a resource instance (lazy-loaded singleton)
	 *
	 * Retrieves or creates an instance of a framework resource.
	 * If the resource already exists, returns the cached instance.
	 * If not, creates it, caches it, then returns it.
	 *
	 * Usage:
	 *   $db = Registry::get('database');
	 *   $cache = Registry::get('cache');
	 *   $template = Registry::get('template');
	 *
	 * @param string $key Resource name ('database', 'cache', 'template')
	 * @return object The resource instance
	 * @throws \InvalidArgumentException If no resource name provided or resource not registered
	 * @throws \RuntimeException If resource creation fails
	 */
	public static function get($key)
	{
		// Validate that a resource name was provided
		if (func_num_args() == 0) {
			throw new \InvalidArgumentException('Registry::get() requires a resource name');
		}

		// Get any additional arguments passed
		$args = array_slice(func_get_args(), 1);

		// Return cached instance if it exists
		if (isset(self::$instances[$key])) {
			return self::$instances[$key];
		}

		// Check if this is a registered resource
		if (!in_array($key, self::$resources)) {
			throw new \InvalidArgumentException("Resource '{$key}' is not registered in Registry");
		}

		// Create the resource instance
		$instance = self::getInstance($key, $args);

		if (!$instance) {
			throw new \RuntimeException("Failed to create instance of resource '{$key}'");
		}

		// Cache and return the instance
		self::$instances[$key] = $instance;
		return self::$instances[$key];
	}

	/**
	 * Manually set/store an object instance
	 *
	 * Allows storing arbitrary object instances in the registry.
	 * Useful for dependency injection or storing custom singletons.
	 *
	 * @param string $key Identifier for the instance
	 * @param object $instance The object to store
	 * @return void
	 */
	public static function set($key, $instance)
	{		
		self::$instances[$key] = $instance;
	}

	/**
	 * Set application settings (from settings.php)
	 *
	 * @param array $settings Application configuration array
	 * @return self For method chaining
	 */
	public static function setSettings($settings)
	{
		self::$settings = $settings;
		return new self;
	}

	/**
	 * Set database configuration (from database.php)
	 *
	 * @param array $database Database configuration array
	 * @return self For method chaining
	 */
	public static function setDatabase($database)
	{
		self::$database = $database;
		return new self;
	}

	/**
	 * Set cache configuration (from cache.php)
	 *
	 * @param array $cache Cache configuration array
	 * @return self For method chaining
	 */
	public static function setCache($cache)
	{
		self::$cache = $cache;
		return new self;
	}

	/**
	 * Set mail configuration (from mail.php)
	 *
	 * @param array $mail Mail configuration array
	 * @return self For method chaining
	 */
	public static function setMail($mail)
	{
		self::$mail = $mail;
		return new self;
	}

	/**
	 * Set the current request URL
	 *
	 * @param string $url The URL string for this request
	 * @return self For method chaining
	 */
	public static function setUrl($url)
	{
		self::$url = $url;
		return new self;
	}
    
    /**
     * Shorthand getter for settings
     * 
     * @return array Application settings
     */
    public static function settings()
    {
        return self::$settings;
    }
    
    /**
     * Shorthand getter for database config
     * 
     * @return array Database configuration
     */
    public static function database()
    {
        return self::$database;
    }
    
    /**
     * Shorthand getter for cache config
     * 
     * @return array Cache configuration
     */
    public static function cache()
    {
        return self::$cache;
    }
    
    /**
     * Shorthand getter for mail config
     * 
     * @return array Mail configuration
     */
    public static function mail()
    {
        return self::$mail;
    }
    
    /**
     * Shorthand getter for URL
     * 
     * @return string Current request URL
     */
    public static function url()
    {
        return self::$url;
    }
    

	/**
	 * Create a resource instance (internal factory method)
	 *
	 * Dynamically calls the appropriate get{Resource} method
	 * to create the requested resource instance.
	 *
	 * @param string $key Resource name
	 * @param array $args Additional arguments
	 * @return object Resource instance
	 */
	private static function getInstance($key, $args)
	{
		// Build method name: 'database' => 'getDatabase'
		$method = 'get' . ucfirst($key) . 'Instance';

		// Call the factory method
		return self::$method($args);
	}

	/**
	 * Create template engine instance
	 *
	 * @param array $args Additional arguments (unused)
	 * @return BaseTemplateClass
	 */
	private static function getTemplateInstance($args)
	{
		return new BaseTemplateClass();
	}

	/**
	 * Create database connection instance
	 *
	 * @param array $args Additional arguments (unused)
	 * @return object Database connection instance
	 * @throws \RuntimeException If database config not loaded
	 */
	private static function getDatabaseInstance($args)
	{
		// Get stored database configuration
		$config = self::$database;

		if (empty($config)) {
			throw new \RuntimeException('Database configuration not loaded. Call Registry::setDatabase() first.');
		}

		// Create and connect database instance
		$instance = new Database(
			$config['default'],
			$config[$config['default']]
		);

		return $instance->initialize()->connect();
	}

	/**
	 * Create cache instance
	 *
	 * @param array $args Additional arguments (unused)
	 * @return CacheBase Cache instance
	 * @throws \RuntimeException If cache config not loaded
	 */
	private static function getCacheInstance($args)
	{
		$config = self::$cache;

		if (empty($config)) {
			throw new \RuntimeException('Cache configuration not loaded. Call Registry::setCache() first.');
		}

		// Create cache instance based on config
		// (Implementation depends on your CacheBase class)
		return new CacheBase($config);
	}

	/**
	 * Remove an instance from the registry
	 *
	 * Useful for clearing cached instances or freeing memory.
	 *
	 * @param string $key The instance identifier to remove
	 * @return void
	 */
	public static function erase($key)
	{
		unset(self::$instances[$key]);
	}

}