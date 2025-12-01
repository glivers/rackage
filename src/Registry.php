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
use Rackage\Cache\CacheHandler;
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
	 * Flag indicating if current page should be cached
	 * Set by Router after caching decision, read by View
	 *
	 * @var bool
	 */
	private static $shouldCache = false;

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
		if (isset(static::$instances[$key])) {
			return static::$instances[$key];
		}

		// Check if this is a registered resource
		if (!in_array($key, static::$resources)) {
			throw new \InvalidArgumentException("Resource '{$key}' is not registered in Registry");
		}

		// Create the resource instance
		$instance = static::getInstance($key, $args);

		if (!$instance) {
			throw new \RuntimeException("Failed to create instance of resource '{$key}'");
		}

		// Cache and return the instance
		static::$instances[$key] = $instance;
		return static::$instances[$key];
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
		static::$instances[$key] = $instance;
	}

	/**
	 * Set application settings (from settings.php)
	 *
	 * @param array $settings Application configuration array
	 * @return self For method chaining
	 */
	public static function setSettings($settings)
	{
		static::$settings = $settings;
		return new static;
	}

	/**
	 * Set database configuration (from database.php)
	 *
	 * @param array $database Database configuration array
	 * @return self For method chaining
	 */
	public static function setDatabase($database)
	{
		static::$database = $database;
		return new static;
	}

	/**
	 * Set cache configuration (from cache.php)
	 *
	 * @param array $cache Cache configuration array
	 * @return self For method chaining
	 */
	public static function setCache($cache)
	{
		static::$cache = $cache;
		return new static;
	}

	/**
	 * Set mail configuration (from mail.php)
	 *
	 * @param array $mail Mail configuration array
	 * @return self For method chaining
	 */
	public static function setMail($mail)
	{
		static::$mail = $mail;
		return new static;
	}

	/**
	 * Set the current request URL
	 *
	 * @param string $url The URL string for this request
	 * @return self For method chaining
	 */
	public static function setUrl($url)
	{
		static::$url = $url;
		return new static;
	}

	/**
	 * Set whether current page should be cached
	 *
	 * @param bool $shouldCache True if page should be cached
	 * @return void
	 */
	public static function setShouldCache($shouldCache)
	{
		static::$shouldCache = $shouldCache;
	}

    /**
     * Shorthand getter for settings
     *
     * @return array Application settings
     */
    public static function settings()
    {
        return static::$settings;
    }
    
    /**
     * Shorthand getter for database config
     * 
     * @return array Database configuration
     */
    public static function database()
    {
        return static::$database;
    }
    
    /**
     * Shorthand getter for cache config
     * 
     * @return array Cache configuration
     */
    public static function cache()
    {
        return static::$cache;
    }
    
    /**
     * Shorthand getter for mail config
     * 
     * @return array Mail configuration
     */
    public static function mail()
    {
        return static::$mail;
    }
    
    /**
     * Shorthand getter for URL
     *
     * @return string Current request URL
     */
    public static function url()
    {
        return static::$url;
    }

    /**
     * Shorthand getter for shouldCache flag
     *
     * @return bool Whether current page should be cached
     */
    public static function shouldCache()
    {
        return static::$shouldCache;
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
		return static::$method($args);
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
		$config = static::$database;

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
	 * Creates appropriate cache driver based on configuration.
	 * Similar to getDatabaseInstance() pattern.
	 *
	 * @param array $args Additional arguments (unused)
	 * @return object Cache driver instance (FileCache, Memcached, or RedisCache)
	 * @throws \RuntimeException If cache config not loaded
	 */
	private static function getCacheInstance($args)
	{
		// Get stored cache configuration
		$config = static::$cache;

		if (empty($config)) {
			throw new \RuntimeException('Cache configuration not loaded. Call Registry::setCache() first.');
		}

		// Get default driver type
		$driver = $config['default'] ?? 'file';

		// Get driver-specific options
		$options = $config['drivers'][$driver] ?? [];

		// Create cache handler and initialize driver
		$instance = new CacheHandler($driver, $options);

		return $instance->initialize();
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
		unset(static::$instances[$key]);
	}

}