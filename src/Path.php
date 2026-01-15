<?php namespace Rackage;

/**
 * Path Helper
 *
 * Provides methods for resolving file system paths in the application.
 * All paths use the url_separator setting from configuration for consistency.
 *
 * Common Methods:
 *   - Path::app()    - Application directory path
 *   - Path::base()   - Root directory path
 *   - Path::sys()    - System directory path
 *   - Path::vault()  - Vault directory path
 *   - Path::tmp()    - Temporary directory path
 *   - Path::view()   - View file path (respects url_separator)
 *
 * Examples:
 *   Path::view('blog/show')     → application/views/blog/show.php
 *   Path::view('errors/404')    → application/views/errors/404.php
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Path
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\Registry;

class Path {

	/**
	 * Private constructor to prevent instantiation
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Prevent cloning
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Get path to application directory
	 *
	 * Returns the absolute path to the application directory
	 * where controllers, models, and views are stored.
	 *
	 * Example:
	 *   Path::app()  // /var/www/myapp/application/
	 *
	 * @return string Absolute path to application directory
	 */
	public static function app()
	{
		return Registry::settings()['root'] . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to root directory
	 *
	 * Returns the absolute path to the application root directory.
	 *
	 * Example:
	 *   Path::base()  // /var/www/myapp/
	 *
	 * @return string Absolute path to root directory
	 */
	public static function base()
	{
		return Registry::settings()['root'] . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to system directory
	 *
	 * Returns the absolute path to the system directory
	 * where framework core files are stored.
	 *
	 * Example:
	 *   Path::sys()  // /var/www/myapp/system/
	 *
	 * @return string Absolute path to system directory
	 */
	public static function sys()
	{
		return Registry::settings()['root'] . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to vault directory
	 *
	 * Returns the absolute path to the vault directory where
	 * private application data is stored (logs, cache, sessions, tmp).
	 *
	 * Example:
	 *   Path::vault()  // /var/www/myapp/vault/
	 *
	 * @return string Absolute path to vault directory
	 */
	public static function vault()
	{
		return Registry::settings()['root'] . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to tmp directory
	 *
	 * Returns the absolute path to the temporary directory
	 * where compiled views and cache files are stored.
	 *
	 * Example:
	 *   Path::tmp()  // /var/www/myapp/vault/tmp/
	 *
	 * @return string Absolute path to tmp directory
	 */
	public static function tmp()
	{
		return self::vault() . 'tmp' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to view file
	 *
	 * Converts view name to absolute file path using the url_separator
	 * setting from configuration. The separator is used to split the
	 * view name into directory segments.
	 *
	 * Custom View Paths:
	 *   If view_paths is configured in settings.php, checks those directories
	 *   first before falling back to application/views/.
	 *
	 * Separator is read from: settings.php → url_separator
	 *
	 * Examples:
	 *   // With url_separator = '/'
	 *   Path::view('blog/show')     → /path/to/application/views/blog/show.php
	 *   Path::view('errors/404')    → /path/to/application/views/errors/404.php
	 *   Path::view('home')          → /path/to/application/views/home.php
	 *
	 *   // With view_paths = ['themes/']
	 *   Path::view('aurora/home')   → /path/to/themes/aurora/home.php (if exists)
	 *                               → /path/to/application/views/aurora/home.php (fallback)
	 *
	 * @param string $fileName View name using configured separator
	 * @return string Absolute path to view file
	 */
	public static function view($fileName)
	{
		// Get separator from settings (defaults to '/' if not set)
		$separator = Registry::settings()['url_separator'] ?? '/';

		// Split view name by separator and filter out empty elements
		$array = array_filter(explode($separator, $fileName), 'strlen');

		// Build relative path (guaranteed no leading/trailing slashes)
		$relativePath = join(DIRECTORY_SEPARATOR, $array) . '.php';

		// Check custom view paths first
		$customPaths = Registry::settings()['view_paths'] ?? [];

		if (!empty($customPaths)) {
			// Clean array once: remove application/views and exact duplicates
			$cleaned = [];
			$seen = [];

			foreach ($customPaths as $path) {
				$trimmed = trim($path, '/\\');
				$normalized = str_replace('\\', '/', $trimmed);

				// Skip application/views (case-insensitive check)
				if (strtolower($normalized) === 'application/views') {
					continue;
				}

				// Skip exact duplicates (case-sensitive for Linux compatibility)
				if (isset($seen[$normalized])) {
					continue;
				}

				$seen[$normalized] = true;
				$cleaned[] = $trimmed;
			}

			// Iterate through cleaned paths
			foreach ($cleaned as $basePath) {
				$fullPath = Registry::settings()['root'] . DIRECTORY_SEPARATOR
						  . $basePath . DIRECTORY_SEPARATOR
						  . $relativePath;

				if (file_exists($fullPath)) {
					return $fullPath;
				}
			}
		}

		// Fallback to default application/views/ directory
		return Registry::settings()['root'] . DIRECTORY_SEPARATOR
			. 'application' . DIRECTORY_SEPARATOR
			. 'views' . DIRECTORY_SEPARATOR
			. $relativePath;
	}

}
