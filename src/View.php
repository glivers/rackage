<?php namespace Rackage;

/**
 * View Handler
 *
 * Handles rendering of view files with optional template compilation.
 * Provides methods for passing data to views, rendering with or without
 * template engine, and outputting JSON responses.
 *
 * Static Design:
 *   All methods are static - no instance creation required.
 *   Variables are stored in static properties and extracted into view scope.
 *
 * Template Engine:
 *   When enabled (settings.php → template_engine: true), views are compiled:
 *   - Directives (@if, @foreach, @extends, etc.) are processed
 *   - Echo tags ({{ }}, {{{ }}}) are compiled
 *   - View helpers are auto-imported
 *
 * Usage Patterns:
 *
 *   // Simple rendering with data
 *   View::render('blog/show', ['post' => $post]);
 *
 *   // With HTTP status code
 *   View::render('maintenance', [], 503);
 *
 *   // Chaining multiple data sources
 *   View::with(['user' => $user])
 *       ->with(['posts' => $posts])
 *       ->render('dashboard');
 *
 *   // Raw rendering (no template compilation)
 *   View::renderRaw('legacy.php', ['data' => $data]);
 *
 *   // Error pages
 *   View::error(404);
 *   View::error(500, ['message' => 'Database error']);
 *
 *   // Get compiled template content without rendering
 *   $compiled = View::get('emails/welcome');
 *
 *   // JSON response
 *   View::json(['status' => 'success']);
 *   View::json(['error' => 'Not found'], 404);
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\View
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\Registry;
use Rackage\Path;
use Rackage\Templates\Template;
use Rackage\Templates\TemplateException;

class View {

    /**
     * Template parser singleton instance
     * @var Template|null
     */
    private static $templateEngine = null;

    /**
     * Variables to inject into view files
     * Stored as single merged array (not nested arrays)
     * @var array
     */
    private static $variables = array();

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
     * Get template parser singleton instance
     *
     * Creates Template instance on first call,
     * returns cached instance on subsequent calls.
     *
     * @return Template Template parser instance
     */
    public static function getTemplateEngine()
    {
        if (is_null(self::$templateEngine)) {
            self::$templateEngine = new Template();
        }

        return self::$templateEngine;
    }

    /**
     * Generate view helper imports header
     *
     * Creates PHP 'use' statements for all classes listed in the 'view_helpers'
     * configuration setting. This allows helper classes to be used in views without
     * fully qualifying their namespaces.
     *
     * Example output:
     *   <?php
     *   use Rackage\Url;
     *   use Rackage\HTML;
     *   ?>
     *
     * @return string PHP opening tag with use statements
     */
    private static function getHeaderContent()
    {
        $view_helpers = Registry::settings()['view_helpers'];

        $use_statements = array();
        foreach ($view_helpers as $namespace) {
            $use_statements[] = "use $namespace;";
        }

        $use_string = "\n" . join("\n", $use_statements);

        return '<?php ' . $use_string . "\n?>\n";
    }

    /**
     * Set view variables to pass to template
     *
     * Accepts an associative array of variables to make available in the view.
     * Can be called multiple times - data is merged together.
     *
     * Examples:
     *   View::with(['user' => $user])->render('profile');
     *
     *   View::with(['title' => 'Dashboard'])
     *       ->with(['posts' => $posts])
     *       ->render('admin/dashboard');
     *
     * @param array $data Associative array of variables
     * @return View Returns new instance for method chaining
     */
    public static function with(array $data)
    {
        // Merge data into single array (not nested)
        self::$variables = array_merge(self::$variables, $data);

        return new static;
    }

    /**
     * Render view file with template compilation
     *
     * Renders a view file with template engine enabled (unless disabled globally).
     * Variables are extracted into local scope and the view is included.
     *
     * Examples:
     *   View::render('home');
     *   View::render('blog/show', ['post' => $post]);
     *   View::render('maintenance', [], 503);
     *   View::with(['user' => $user])->render('dashboard');
     *
     * @param string $fileName View file path
     * @param array $data Optional associative array of variables
     * @param int $status HTTP status code (default 200)
     * @return void Outputs rendered view directly
     */
    public static function render($fileName, array $data = [], $status = 200)
    {
        self::renderView($fileName, $data, $status, true);
    }

    /**
     * Render view file without template compilation
     *
     * Renders a view file as raw PHP without template engine processing.
     * Useful for legacy views, debugging, or when template syntax causes issues.
     *
     * Examples:
     *   View::renderRaw('legacy.old');
     *   View::renderRaw('debug.output', ['data' => $debugData]);
     *   View::renderRaw('plain', [], 404);
     *
     * @param string $fileName View file path
     * @param array $data Optional associative array of variables
     * @param int $status HTTP status code (default 200)
     * @return void Outputs rendered view directly
     */
    public static function renderRaw($fileName, array $data = [], $status = 200)
    {
        self::renderView($fileName, $data, $status, false);
    }

    /**
     * Render error page with HTTP status code
     *
     * Renders the error page configured in settings.php → error_pages.
     * Sets proper HTTP status code. Falls back to generic message if
     * no error view is configured.
     *
     * Examples:
     *   View::error(404);
     *   View::error(500, ['message' => 'Database connection failed']);
     *   View::error(403, ['reason' => 'Insufficient permissions']);
     *
     * @param int $code HTTP status code
     * @param array $data Optional data to pass to error view
     * @return void Outputs error page directly
     */
    public static function error($code, array $data = [])
    {
        http_response_code($code);

        $errorPages = Registry::settings()['error_pages'] ?? [];
        $errorView = $errorPages[$code] ?? null;

        if ($errorView) {
            self::render($errorView, $data);
        } else {
            echo "<h1>Error $code</h1>";
        }
    }

    /**
     * Internal render implementation
     *
     * Handles the actual rendering logic for both render() and renderRaw().
     * Extracts variables, compiles template (if enabled), writes to temp file,
     * includes it, then cleans up.
     *
     * @param string $fileName View file path
     * @param array $data Variables to pass to view
     * @param int $status HTTP status code
     * @param bool $parse Whether to use template compilation
     * @return void
     */
    private static function renderView($fileName, array $data, $status, $parse)
    {
        try {
            // Set HTTP status code
            if ($status !== 200) {
                http_response_code($status);
            }

            // Merge passed data with existing variables
            $allVariables = array_merge(self::$variables, $data);

            // Extract variables into local scope
            foreach ($allVariables as $key => $value) {
                $$key = $value;
            }

            // Ensure tmp directory exists
            $tmpDir = Registry::settings()['root'] . '/vault/tmp/';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            // Determine whether to parse template
            $shouldParse = $parse && (Registry::settings()['template_engine'] !== false);

            // Get view contents (compiled or raw)
            if ($shouldParse) {
                $contents = self::getHeaderContent() . self::getContents($fileName, false);
            } else {
                $filePath = Path::view($fileName);
                $contents = self::getHeaderContent() . file_get_contents($filePath);
            }

            // Write to temp file, include, and clean up
            $file_write_path = $tmpDir . uniqid('view_', true) . '.php';
            file_put_contents($file_write_path, $contents);
            include $file_write_path;
            unlink($file_write_path);

            // Clear variables after rendering
            self::$variables = array();
        }
        catch (TemplateException $exception) {
            throw $exception;
        }
    }

    /**
     * Get compiled template contents without rendering
     *
     * Returns the compiled PHP code of a template file without executing it.
     * Useful for debugging template compilation or generating email content.
     *
     * Example:
     *   $emailHtml = View::get('emails/welcome');
     *
     * @param string $fileName View file path
     * @return string Compiled PHP code
     */
    public static function get($fileName)
    {
        try {
            return self::getContents($fileName, false);
        }
        catch (TemplateException $exception) {
            throw $exception;
        }
    }

    /**
     * Compile template file to PHP code
     *
     * Processes a template file through the template engine, converting
     * directives and echo tags into valid PHP code.
     *
     * @param string $fileName View file name
     * @param bool $embedded Whether this is embedded in another view
     * @return string Compiled PHP code
     */
    public static function getContents($fileName, $embedded)
    {
        $filePath = Path::view($fileName);
        $template = self::getTemplateEngine();
        $contents = $template->compiled($filePath, $embedded, $fileName);

        return $contents;
    }

    /**
     * Output JSON response
     *
     * Sets JSON content-type header and outputs data as JSON.
     * Optionally sets HTTP status code.
     *
     * Examples:
     *   View::json(['status' => 'success']);
     *   View::json(['error' => 'Not found'], 404);
     *   View::json(['data' => $results], 201);
     *
     * @param array|null $data Data to encode as JSON
     * @param int $status HTTP status code (default 200)
     * @return void Outputs JSON directly
     */
    public static function json(array $data = null, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

}
