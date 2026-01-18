<?php namespace Rackage;

/**
 * Log Helper
 *
 * Provides application logging with multiple severity levels and structured
 * context data. Writes formatted log entries to files in vault/logs/ directory.
 *
 * This helper uses a static API for simple logging and supports instance methods
 * for custom log files via the to() method. All log operations are non-blocking
 * and never halt script execution, even if writes fail.
 *
 * Log Levels (in order of severity):
 *   - ERROR: Runtime errors that should be investigated immediately
 *   - WARNING: Exceptional occurrences that are not errors (deprecated APIs, poor use of API)
 *   - INFO: Interesting events (user login, SQL logs, significant actions)
 *   - DEBUG: Detailed debug information for development
 *
 * File Structure:
 *   All logs written to vault/logs/ directory:
 *   - error.log (default): ERROR and WARNING messages
 *   - app.log: INFO messages (if using Log::info())
 *   - debug.log: DEBUG messages (if using Log::debug())
 *   - custom.log: Via Log::to('custom.log')
 *
 * Log Format:
 *   [2024-01-15 14:30:45] [ERROR] Database connection failed {"host":"localhost","error":"Access denied"}
 *   [timestamp] [LEVEL] message {context_json}
 *
 * Context Data:
 *   Second parameter accepts associative array for structured logging.
 *   Automatically JSON-encoded for easy parsing by log analyzers.
 *
 * Common Use Cases:
 *   - Error tracking: Log exceptions and runtime errors
 *   - Security auditing: Track login attempts, permission changes, suspicious activity
 *   - Performance monitoring: Log slow queries, high memory usage, timeouts
 *   - Debugging: Track variable values, execution flow, API responses
 *   - User activity: Track important user actions for analytics
 *
 * Static vs Instance:
 *   Static methods (Log::error(), Log::info()) write to default files.
 *   Instance methods (Log::to('file.log')->error()) write to custom files.
 *   Use static for 99% of cases, instance for special log separation.
 *
 * Configuration (config/settings.php):
 *   'log_level' => 'debug',  // debug, info, warning, error (filters what gets written)
 *   'log_path' => 'vault/logs/',
 *
 * Performance:
 *   - Non-blocking file writes with exclusive locks (LOCK_EX)
 *   - Auto-creates log directory if missing
 *   - Silently fails on write errors (never crashes app)
 *   - Use enabled() check before expensive debug operations
 *
 * Usage Examples:
 *
 *   // Simple error logging
 *   Log::error('Payment gateway timeout');
 *   Log::warning('Cache miss rate exceeds threshold');
 *   Log::info('User registered');
 *   Log::debug('API response received');
 *
 *   // With context data
 *   Log::error('Database query failed', [
 *       'query' => $sql,
 *       'error' => $db->error,
 *       'user_id' => Session::get('user_id')
 *   ]);
 *
 *   Log::warning('Slow query detected', [
 *       'query' => $sql,
 *       'duration_ms' => $duration,
 *       'threshold_ms' => 1000
 *   ]);
 *
 *   Log::info('User login', [
 *       'user_id' => $user['id'],
 *       'email' => $user['email'],
 *       'ip' => Request::ip()
 *   ]);
 *
 *   // Custom log file
 *   Log::to('security.log')->warning('Failed login attempt', [
 *       'email' => Input::post('email'),
 *       'ip' => Request::ip(),
 *       'user_agent' => Request::agent()
 *   ]);
 *
 *   // Conditional expensive logging
 *   if (Log::enabled('debug')) {
 *       $trace = debug_backtrace();
 *       Log::debug('Execution trace', ['trace' => $trace]);
 *   }
 *
 *   // Exception logging
 *   try {
 *       $result = riskyOperation();
 *   } catch (Exception $e) {
 *       Log::error('Operation failed: ' . $e->getMessage(), [
 *           'exception' => get_class($e),
 *           'file' => $e->getFile(),
 *           'line' => $e->getLine(),
 *           'trace' => $e->getTraceAsString()
 *       ]);
 *   }
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Log
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

class Log {

	/**
	 * Log level hierarchy for filtering
	 * Higher numbers = more severe, lower numbers = more verbose
	 *
	 * @var array
	 */
	private static $levels = [
		'debug' => 1,
		'info' => 2,
		'warning' => 3,
		'error' => 4,
	];

	/**
	 * Default log files for each level
	 *
	 * @var array
	 */
	private static $defaultFiles = [
		'debug' => 'debug.log',
		'info' => 'app.log',
		'warning' => 'error.log',
		'error' => 'error.log',
	];

	/**
	 * Custom log file for instance-based logging
	 * Set via to() method
	 *
	 * @var string|null
	 */
	private $customFile = null;

	/**
	 * Cached configuration
	 *
	 * @var array
	 */
	private static $config = [];

	/**
	 * Private constructor to prevent instantiation
	 *
	 * This class uses static methods only - no instances should be created
	 * directly. Use Log::to() to get an instance for custom file logging.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning
	 *
	 * Ensures singleton-like behavior even though we don't use instances
	 * for static methods.
	 */
	private function __clone() {}

	// ===========================================================================
	// STATIC LOG METHODS (Default Files)
	// ===========================================================================

	/**
	 * Log error message
	 *
	 * Logs runtime errors that should be investigated immediately.
	 * Use for exceptions, database errors, failed API calls, file system errors.
	 *
	 * Process:
	 * 1. Check if error level is enabled in config
	 * 2. Format message with timestamp and ERROR level
	 * 3. Append context data as JSON if provided
	 * 4. Write to error.log file
	 * 5. Auto-create log directory if missing
	 *
	 * When to Use:
	 *   - Exceptions and fatal errors
	 *   - Database connection failures
	 *   - File system errors (permissions, not found)
	 *   - Payment gateway failures
	 *   - External API errors
	 *
	 * Usage:
	 *   Log::error('Database connection failed');
	 *   Log::error('Payment processing failed', ['order_id' => 123, 'gateway' => 'stripe']);
	 *
	 *   try {
	 *       $db = Database::connect();
	 *   } catch (Exception $e) {
	 *       Log::error('Database error: ' . $e->getMessage(), [
	 *           'file' => $e->getFile(),
	 *           'line' => $e->getLine()
	 *       ]);
	 *   }
	 *
	 * @param string $message Error message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function error($message, $context = [])
	{
		self::write('error', $message, $context);
	}

	/**
	 * Log warning message
	 *
	 * Logs exceptional occurrences that are not errors but should be reviewed.
	 * Use for deprecated features, slow operations, unusual API usage, validation failures.
	 *
	 * Process:
	 * 1. Check if warning level is enabled in config
	 * 2. Format message with timestamp and WARNING level
	 * 3. Append context data as JSON if provided
	 * 4. Write to error.log file
	 *
	 * When to Use:
	 *   - Deprecated API usage
	 *   - Slow database queries (above threshold)
	 *   - High memory usage
	 *   - Failed login attempts (security)
	 *   - Cache misses above threshold
	 *   - Unusual API parameters
	 *
	 * Usage:
	 *   Log::warning('Slow query detected', ['duration_ms' => 2500, 'query' => $sql]);
	 *   Log::warning('Cache miss rate high', ['rate' => 0.85, 'threshold' => 0.7]);
	 *   Log::warning('Deprecated method called', ['method' => 'oldFunction', 'use_instead' => 'newFunction']);
	 *
	 * @param string $message Warning message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function warning($message, $context = [])
	{
		self::write('warning', $message, $context);
	}

	/**
	 * Log info message
	 *
	 * Logs interesting events and significant user actions.
	 * Use for user activity tracking, important state changes, scheduled tasks.
	 *
	 * Process:
	 * 1. Check if info level is enabled in config
	 * 2. Format message with timestamp and INFO level
	 * 3. Append context data as JSON if provided
	 * 4. Write to app.log file
	 *
	 * When to Use:
	 *   - User login/logout
	 *   - User registration
	 *   - Password changes
	 *   - Important CRUD operations
	 *   - Scheduled task execution
	 *   - Email sent successfully
	 *   - Payment completed
	 *
	 * Usage:
	 *   Log::info('User registered', ['email' => $email, 'ip' => Request::ip()]);
	 *   Log::info('Email sent', ['to' => $recipient, 'subject' => $subject]);
	 *   Log::info('Scheduled task completed', ['task' => 'backup', 'duration' => $seconds]);
	 *
	 * @param string $message Info message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function info($message, $context = [])
	{
		self::write('info', $message, $context);
	}

	/**
	 * Log debug message
	 *
	 * Logs detailed debug information for development and troubleshooting.
	 * Use for variable dumps, execution flow, API responses, query details.
	 *
	 * Process:
	 * 1. Check if debug level is enabled in config
	 * 2. Format message with timestamp and DEBUG level
	 * 3. Append context data as JSON if provided
	 * 4. Write to debug.log file
	 *
	 * When to Use:
	 *   - Variable values during debugging
	 *   - Execution flow tracking
	 *   - API request/response data
	 *   - Query execution details
	 *   - Cache hit/miss details
	 *   - Session data inspection
	 *
	 * Best Practice:
	 *   Always wrap expensive debug logging in enabled() check to avoid
	 *   performance impact in production.
	 *
	 * Usage:
	 *   Log::debug('API response received', ['status' => 200, 'body' => $response]);
	 *   Log::debug('Query executed', ['sql' => $sql, 'params' => $params, 'rows' => $count]);
	 *
	 *   // Conditional expensive logging
	 *   if (Log::enabled('debug')) {
	 *       $trace = debug_backtrace();
	 *       Log::debug('Execution trace', ['trace' => $trace]);
	 *   }
	 *
	 * @param string $message Debug message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function debug($message, $context = [])
	{
		self::write('debug', $message, $context);
	}

	// ===========================================================================
	// INSTANCE METHODS (Custom Files)
	// ===========================================================================

	/**
	 * Create instance for custom log file
	 *
	 * Returns a Log instance configured to write to a custom file instead
	 * of the default log files. Useful for separating logs by purpose
	 * (security, cron jobs, integrations, etc.).
	 *
	 * Process:
	 * 1. Create new Log instance
	 * 2. Set custom file name
	 * 3. Return instance for chaining
	 *
	 * File Location:
	 *   All custom files written to vault/logs/ directory.
	 *   Provide just the filename, not full path.
	 *
	 * Common Use Cases:
	 *   - Security logs: Failed logins, permission changes, suspicious activity
	 *   - Integration logs: External API calls, webhooks, third-party services
	 *   - Scheduled task logs: Cron jobs, background workers
	 *   - Feature-specific logs: Payment processing, email delivery
	 *
	 * Usage:
	 *   Log::to('security.log')->error('Brute force detected', ['ip' => $ip, 'attempts' => 10]);
	 *   Log::to('cron.log')->info('Backup completed', ['size_mb' => $size, 'duration' => $seconds]);
	 *   Log::to('stripe.log')->warning('Webhook validation failed', ['signature' => $sig]);
	 *
	 * @param string $filename Log filename (stored in vault/logs/)
	 * @return Log Instance for chaining
	 */
	public static function to($filename)
	{
		$instance = new self();
		$instance->customFile = $filename;
		return $instance;
	}

	/**
	 * Instance: Log error to custom file
	 *
	 * Same as static error() but writes to custom file set via to().
	 *
	 * @param string $message Error message
	 * @param array $context Additional context data
	 * @return void
	 */
	public function error($message, $context = [])
	{
		$this->writeInstance('error', $message, $context);
	}

	/**
	 * Instance: Log warning to custom file
	 *
	 * Same as static warning() but writes to custom file set via to().
	 *
	 * @param string $message Warning message
	 * @param array $context Additional context data
	 * @return void
	 */
	public function warning($message, $context = [])
	{
		$this->writeInstance('warning', $message, $context);
	}

	/**
	 * Instance: Log info to custom file
	 *
	 * Same as static info() but writes to custom file set via to().
	 *
	 * @param string $message Info message
	 * @param array $context Additional context data
	 * @return void
	 */
	public function info($message, $context = [])
	{
		$this->writeInstance('info', $message, $context);
	}

	/**
	 * Instance: Log debug to custom file
	 *
	 * Same as static debug() but writes to custom file set via to().
	 *
	 * @param string $message Debug message
	 * @param array $context Additional context data
	 * @return void
	 */
	public function debug($message, $context = [])
	{
		$this->writeInstance('debug', $message, $context);
	}

	// ===========================================================================
	// UTILITY METHODS
	// ===========================================================================

	/**
	 * Check if log level is enabled
	 *
	 * Determines if a specific log level will be written based on configuration.
	 * Use this before expensive debug operations to avoid performance impact.
	 *
	 * Process:
	 * 1. Load configuration
	 * 2. Get configured minimum log level
	 * 3. Compare requested level against minimum
	 * 4. Return true if level is enabled
	 *
	 * Log Level Hierarchy:
	 *   debug < info < warning < error
	 *
	 * Examples:
	 *   If config log_level = 'info':
	 *     - debug: disabled (too verbose)
	 *     - info: enabled
	 *     - warning: enabled
	 *     - error: enabled
	 *
	 * Usage:
	 *   // Check before expensive operations
	 *   if (Log::enabled('debug')) {
	 *       $trace = debug_backtrace();
	 *       $vars = get_defined_vars();
	 *       Log::debug('Debug snapshot', ['trace' => $trace, 'vars' => $vars]);
	 *   }
	 *
	 *   // General check
	 *   if (Log::enabled()) {
	 *       Log::info('Logging is enabled');
	 *   }
	 *
	 * @param string $level Log level to check (debug, info, warning, error)
	 * @return bool True if level is enabled, false otherwise
	 */
	public static function enabled($level = 'debug')
	{
		self::loadConfig();

		// Get configured minimum level (default: debug = log everything)
		$configLevel = self::$config['log_level'] ?? 'debug';

		// Get numeric values for comparison
		$configValue = self::$levels[$configLevel] ?? 1;
		$checkValue = self::$levels[$level] ?? 1;

		// Level is enabled if it's >= configured minimum
		return $checkValue >= $configValue;
	}

	// ===========================================================================
	// INTERNAL HELPERS
	// ===========================================================================

	/**
	 * Write log entry (static method)
	 *
	 * Internal method that handles actual log writing for static log methods.
	 * Formats message, checks level filtering, and writes to appropriate file.
	 *
	 * Process:
	 * 1. Check if level is enabled in config
	 * 2. Load configuration if needed
	 * 3. Build log entry: [timestamp] [LEVEL] message {context}
	 * 4. Determine target file from level
	 * 5. Ensure log directory exists
	 * 6. Write to file with exclusive lock
	 * 7. Silently fail on write errors (never crash app)
	 *
	 * Format:
	 *   [2024-01-15 14:30:45] [ERROR] Database connection failed {"host":"localhost"}
	 *
	 * @param string $level Log level (debug, info, warning, error)
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	private static function write($level, $message, $context = [])
	{
		// Check if level is enabled
		if (!self::enabled($level)) {
			return;
		}

		self::loadConfig();

		// Build log entry
		$entry = self::formatEntry($level, $message, $context);

		// Determine file
		$filename = self::$defaultFiles[$level] ?? 'error.log';
		$filepath = self::getLogPath($filename);

		// Write to file
		self::writeToFile($filepath, $entry);
	}

	/**
	 * Write log entry (instance method)
	 *
	 * Internal method for instance-based logging to custom files.
	 * Same as write() but uses custom file instead of default.
	 *
	 * @param string $level Log level
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	private function writeInstance($level, $message, $context = [])
	{
		// Check if level is enabled
		if (!self::enabled($level)) {
			return;
		}

		self::loadConfig();

		// Build log entry
		$entry = self::formatEntry($level, $message, $context);

		// Use custom file
		$filepath = self::getLogPath($this->customFile);

		// Write to file
		self::writeToFile($filepath, $entry);
	}

	/**
	 * Format log entry
	 *
	 * Builds formatted log string with timestamp, level, message, and context.
	 *
	 * Format:
	 *   [2024-01-15 14:30:45] [ERROR] message
	 *   [2024-01-15 14:30:45] [ERROR] message {"key":"value"}
	 *
	 * Context Encoding:
	 *   - Empty array: not appended
	 *   - Non-empty: JSON-encoded and appended
	 *   - Encoding failure: appended as (context encoding failed)
	 *
	 * @param string $level Log level
	 * @param string $message Log message
	 * @param array $context Context data
	 * @return string Formatted log entry
	 */
	private static function formatEntry($level, $message, $context = [])
	{
		// Build timestamp
		$timestamp = date('Y-m-d H:i:s');

		// Build base entry
		$entry = "[{$timestamp}] [" . strtoupper($level) . "] {$message}";

		// Append context if provided
		if (!empty($context)) {
			$contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($contextJson !== false) {
				$entry .= " {$contextJson}";
			} else {
				$entry .= " (context encoding failed)";
			}
		}

		return $entry;
	}

	/**
	 * Get full log file path
	 *
	 * Builds absolute path to log file in vault/logs/ directory.
	 *
	 * @param string $filename Log filename
	 * @return string Absolute path to log file
	 */
	private static function getLogPath($filename)
	{
		$logDir = self::$config['log_path'] ?? 'vault/logs/';

		// Remove trailing slash if present
		$logDir = rtrim($logDir, '/\\');

		// Build absolute path
		if (class_exists('Rackage\Path')) {
			return Path::base() . $logDir . DIRECTORY_SEPARATOR . $filename;
		}

		// Fallback if Path helper not available
		$baseDir = dirname(dirname(dirname(dirname(__DIR__))));
		return $baseDir . DIRECTORY_SEPARATOR . $logDir . DIRECTORY_SEPARATOR . $filename;
	}

	/**
	 * Write entry to log file
	 *
	 * Writes log entry to file with exclusive lock to prevent corruption.
	 * Auto-creates log directory if it doesn't exist. Silently fails on
	 * write errors to never crash the application.
	 *
	 * Process:
	 * 1. Get directory from filepath
	 * 2. Create directory if missing (with 0755 permissions)
	 * 3. Open file in append mode
	 * 4. Acquire exclusive lock (LOCK_EX)
	 * 5. Write entry with newline
	 * 6. Release lock and close file
	 * 7. Set file permissions to 0644 if new file
	 *
	 * File Locking:
	 *   Uses LOCK_EX to prevent multiple processes from writing simultaneously
	 *   and corrupting the log file.
	 *
	 * Error Handling:
	 *   All failures are silently ignored. Logging should never crash the app.
	 *
	 * @param string $filepath Absolute path to log file
	 * @param string $entry Formatted log entry
	 * @return void
	 */
	private static function writeToFile($filepath, $entry)
	{
		try {
			// Ensure log directory exists
			$logDir = dirname($filepath);
			if (!is_dir($logDir)) {
				@mkdir($logDir, 0755, true);
			}

			// Check if file exists (for permission setting later)
			$isNewFile = !file_exists($filepath);

			// Write to file with exclusive lock
			$handle = @fopen($filepath, 'a');
			if ($handle) {
				if (flock($handle, LOCK_EX)) {
					fwrite($handle, $entry . PHP_EOL);
					flock($handle, LOCK_UN);
				}
				fclose($handle);

				// Set permissions on new files
				if ($isNewFile) {
					@chmod($filepath, 0644);
				}
			}
		} catch (\Exception $e) {
			// Silently fail - logging should never crash the app
		}
	}

	/**
	 * Load configuration
	 *
	 * Loads log configuration from Registry on first use.
	 * Caches configuration to avoid repeated Registry lookups.
	 *
	 * Configuration Keys:
	 *   - log_level: Minimum level to log (debug, info, warning, error)
	 *   - log_path: Directory for log files (default: vault/logs/)
	 *
	 * @return void
	 */
	private static function loadConfig()
	{
		if (empty(self::$config)) {
			if (class_exists('Rackage\Registry')) {
				$settings = Registry::settings();
				self::$config = [
					'log_level' => $settings['log_level'] ?? 'debug',
					'log_path' => $settings['log_path'] ?? 'vault/logs/',
				];
			} else {
				// Fallback if Registry not available
				self::$config = [
					'log_level' => 'debug',
					'log_path' => 'vault/logs/',
				];
			}
		}
	}

}
