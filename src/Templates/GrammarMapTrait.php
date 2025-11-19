<?php namespace Rackage\Templates;

/**
 * Template Grammar Map Trait
 * 
 * PURPOSE:
 * This trait contains ALL the compilation logic for Rachie's template engine.
 * It defines the "grammar" (syntax rules) for the template language and provides
 * methods to convert template syntax into executable PHP code.
 * 
 * WHAT IT DOES:
 * - Defines template syntax rules ({{ }}, {{{ }}}, @directives)
 * - Parses template files using PHP's token_get_all() to distinguish HTML from PHP
 * - Converts template directives (@if, @foreach, etc.) into valid PHP control structures
 * - Converts echo tags ({{ }}, {{{ }}}) into PHP echo statements
 * - Handles inline file inclusion via @include
 * - Handles layout inheritance via @extends, @section, @endsection, @parent
 * 
 * HOW IT WORKS:
 * 1. Takes raw template content as a string
 * 2. Tokenizes it using PHP's lexer (token_get_all)
 * 3. Identifies HTML tokens (T_INLINE_HTML) that contain template syntax
 * 4. Runs multiple "compiler passes" over the HTML:
 *    - First pass: compiles @directives (@if, @foreach, etc.)
 *    - Second pass: compiles echo tags ({{ }}, {{{ }}})
 * 5. Returns fully compiled PHP code ready to execute
 * 
 * ARCHITECTURE:
 * - This is a TRAIT (not a class), designed to be mixed into other classes
 * - Used by: BaseTemplateClass (which extends TemplateParserClass)
 * - Contains both public methods (entry points) and protected methods (internal logic)
 * 
 * SUPPORTED TEMPLATE SYNTAX:
 * - Echo (escaped, secure by default): {{ $variable }}
 * - Echo (raw, unescaped): {{{ $html }}}
 * - Echo with default: {{ $name or 'Guest' }}
 * - Escape directives: @@if → outputs literal @if
 * - Control structures: @if, @else, @elseif, @endif
 * - Loops: @for, @foreach, @endforeach, @while, @endwhile
 * - Special loop: @loopelse / @empty / @endloop (foreach with empty fallback)
 * - File inclusion: @include('path/to/file')
 * - Layout inheritance: @extends('layout'), @section, @endsection, @parent
 * 
 * LAYOUT INHERITANCE SYNTAX:
 * - @extends('layouts/admin')              - Extend a parent layout
 * - @section('name'):                      - Empty placeholder (no closing needed)
 * - @section('name', 'content')            - Inline content (no closing needed)
 * - @section('name') ... @endsection       - Block content with closing
 * - @parent                                - Include parent section content
 * 
 * DEPENDENCIES:
 * - Rackage\View (aliased as View) - for handling @include compilation
 * - Rackage\Registry - for loading configuration settings
 * - PHP's token_get_all() function - for parsing template syntax
 * - HTML::escape() method for escaping output
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Templates
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\View;
use Rackage\Path;
use Rackage\Registry;

trait GrammarMapTrait {

	/**
	 * The file currently being compiled.
	 * This stores the full path to the template file we're working on
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * All of the available compiler functions.
	 * These are the "passes" the compiler makes over the template
	 * First it compiles Statements (@if, @foreach, etc), then Echos ({{ }})
	 *
	 * @var array
	 */
	protected $compilers = array(
		'Statements',  // Handles @directives
		'Echos'        // Handles {{ }} and {{{ }}}
	);

	/**
	 * Array of opening and closing tags for ESCAPED echos (default, secure).
	 * Loaded from config: 'template_echo_tags'
	 * Default: {{ }}
	 *
	 * @var array
	 */
	protected $echoTags = null;

	/**
	 * Array of opening and closing tags for RAW/UNESCAPED echos.
	 * Loaded from config: 'template_raw_tags'
	 * Default: {{{ }}}
	 *
	 * @var array
	 */
	protected $rawEchoTags = null;

	/**
	 * Counter to keep track of nested @loopelse statements.
	 * Each @loopelse creates a variable like $__noitems_1, $__noitems_2, etc
	 * This counter ensures each nested loopelse has a unique variable name
	 *
	 * @var int
	 */
	protected $loopelseCounter = 0;

	/**
	 * Sections defined in the current template (for layout inheritance).
	 * Stores all @section content from child templates.
	 * Format: ['content' => 'html...', 'header' => 'html...']
	 *
	 * @var array
	 */
	protected $sections = array();

	/**
	 * Name of the parent layout being extended (for layout inheritance).
	 * Set by @extends('layout/path') directive in child template.
	 * Format: 'layouts/admin' (relative path without extension)
	 *
	 * @var string|null
	 */
	protected $layout = null;

	/**
	 * Initialize the template tags from config.
	 * Called automatically before compilation
	 *
	 * @return void
	 */
	protected function initializeTags()
	{
		// Only initialize once
		if ($this->echoTags !== null) {
			return;
		}

		// Load tags from config, with fallback to defaults
		$config = Registry::settings();
		
		$this->echoTags = isset($config['template_echo_tags']) 
			? $config['template_echo_tags'] 
			: array('{{', '}}');
			
		$this->rawEchoTags = isset($config['template_raw_tags']) 
			? $config['template_raw_tags'] 
			: array('{{{', '}}}');
	}

	/**
	 * Compile the view at the given path.
	 * This is the MAIN entry point for compilation
	 *
	 * PROCESS (UPDATED FOR NESTED LAYOUT INHERITANCE):
	 * 1. Initialize template tags from config
	 * 2. Reset state for this compilation
	 * 3. ASSEMBLE the complete document (handles @extends recursively)
	 * 4. COMPILE the assembled document (handles @if, {{ }}, etc.)
	 * 5. Return the compiled PHP code
	 *
	 * NESTED LAYOUTS EXAMPLE:
	 *   dashboard.php extends layouts/admin
	 *   layouts/admin extends layouts/base
	 *   Result: dashboard → admin → base (all merged, then compiled)
	 *
	 * @param  string  $path - NOTE: This parameter is never used! We use $this->path instead
	 * @return string The compiled PHP code
	 */
	public function compile($path = null)
	{
		// Initialize template tags from config
		$this->initializeTags();
		
		// Reset state for this compilation
		$this->layout = null;
		$this->sections = array();

		// STEP 1: ASSEMBLY - Recursively merge all layouts
		// This handles @extends, @section, @parent at the TEXT level
		$assembledContent = $this->assembleTemplate($this->path);

		// STEP 2: COMPILATION - Convert template syntax to PHP
		// This handles @if, @foreach, {{ }}, etc.
		return $this->compileString($assembledContent);
	}

	/**
	 * Recursively assemble template with all parent layouts.
	 * 
	 * This method handles layout inheritance by:
	 * 1. Checking if current file extends a parent
	 * 2. If yes, recursively assemble the parent first
	 * 3. Then inject current file's sections into parent
	 * 4. Return the merged result
	 * 
	 * This allows UNLIMITED nesting depth:
	 *   child → parent → grandparent → great-grandparent → ...
	 * 
	 * EXAMPLE FLOW:
	 *   assembleTemplate('dashboard.php')
	 *   ├─ Extracts sections from dashboard.php
	 *   ├─ Sees @extends('layouts/admin')
	 *   ├─ Calls assembleTemplate('layouts/admin.php')
	 *   │  ├─ Extracts sections from layouts/admin.php
	 *   │  ├─ Sees @extends('layouts/base')
	 *   │  ├─ Calls assembleTemplate('layouts/base.php')
	 *   │  │  └─ No @extends, returns raw base.php content
	 *   │  └─ Injects admin sections into base, returns result
	 *   └─ Injects dashboard sections into admin+base, returns final
	 *
	 * @param  string  $filePath Full path to template file
	 * @return string The assembled template content (still has template syntax)
	 */
	protected function assembleTemplate($filePath)
	{
		// Read the raw template file
		$contents = file_get_contents($filePath);
		
		// Check if this template extends a parent layout
		// Pattern: @extends('layout/path') at start of file (ignoring whitespace)
		if (preg_match('/^\s*(?<!@)@extends\(\s*[\'"]([^\'"]+)[\'"]\s*\)/m', $contents, $match)) 
		{
			$layoutName = $match[1];
			
			// Extract all sections from THIS file (before merging with parent)
			$childSections = $this->extractRawSections($contents);
			
			// Get parent layout path
			$parentPath = Path::view($layoutName);
			
			// RECURSIVE CALL: Assemble the parent layout
			// This goes all the way up the inheritance chain
			// If parent also extends something, it will recurse further
			$parentAssembled = $this->assembleTemplate($parentPath);
			
			// Inject this file's sections into the assembled parent
			// This replaces @section('name'): placeholders with our content
			// Also handles @parent directive
			$assembledContent = $this->injectSectionsIntoLayout($parentAssembled, $childSections);
			
			return $assembledContent;
		}
		
		// No @extends directive found
		// This is either a base layout or a standalone template
		// Return the content as-is (still needs compilation)
		return $contents;
	}

	/**
	 * Extract raw section content from child template (BEFORE compilation).
	 * 
	 * This method extracts section content at the RAW TEXT level,
	 * before any compilation happens. It looks for:
	 * 
	 * 1. Inline sections: @section('name', 'content')
	 * 2. Block sections: @section('name') ... @endsection
	 * 
	 * SPECIAL HANDLING:
	 * - If a section contains @parent, we mark it specially
	 * - This allows append/prepend operations later
	 * 
	 * EXAMPLE INPUT:
	 *   @extends('layout')
	 *   @section('title', 'Dashboard')
	 *   @section('content')
	 *     <h1>Hello</h1>
	 *   @endsection
	 * 
	 * EXAMPLE OUTPUT:
	 *   [
	 *     'title' => 'Dashboard',
	 *     'content' => '<h1>Hello</h1>'
	 *   ]
	 *
	 * @param  string  $content The raw template content
	 * @return array Associative array of section names => content
	 */
	protected function extractRawSections($content)
	{
		$sections = array();
		
		// Remove @extends line (but NOT @@extends)
		$content = preg_replace('/^\s*(?<!@)@extends\(\s*[\'"][^\'"]+[\'"]\s*\)\s*/m', '', $content);
		
		// PATTERN 1: Inline sections - @section('name', 'content')
		// Must not be preceded by @ (to respect @@section escaping)
		if (preg_match_all('/(?<!@)@section\(\s*[\'"]([^\'\"]+)[\'"]\s*,\s*[\'"]([^\'\"]*?)[\'"]\s*\)/s', $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$sectionName = $match[1];
				$sectionContent = $match[2];
				
				// Unescape quotes
				$sectionContent = str_replace("\\'", "'", $sectionContent);
				$sectionContent = str_replace('\\"', '"', $sectionContent);
				
				$sections[$sectionName] = $sectionContent;
			}
		}
		
		// PATTERN 2: Block sections - @section('name') ... @endsection
		// Both @section and @endsection must not be preceded by @
		if (preg_match_all('/(?<!@)@section\(\s*[\'"]([^\'\"]+)[\'"]\s*\)(.*?)(?<!@)@endsection/s', $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$sectionName = $match[1];
				$sectionContent = trim($match[2]);
				
				// Check if section contains @parent directive
				// @parent must be followed by whitespace or end of string, and not preceded by @
				if (preg_match('/(?<!@)@parent(?:\s|$)/', $sectionContent)) {
					// Mark this section as needing parent content
					$sections[$sectionName] = array(
						'content' => $sectionContent,
						'has_parent' => true
					);
				} else {
					$sections[$sectionName] = $sectionContent;
				}
			}
		}
		
		return $sections;
	}

	/**
	 * Inject child sections into parent layout placeholders.
	 * 
	 * This method takes the parent layout (raw text) and replaces
	 * all @section('name'): placeholders with child content.
	 * 
	 * PLACEHOLDER TYPES IN PARENT:
	 * - @section('name'): = Empty placeholder, expects child to fill
	 * - @section('name') ... @endsection = Default content, child can override
	 * 
	 * CHILD SECTION TYPES:
	 * - Normal: Replaces parent content completely
	 * - With @parent: Includes parent's content (append/prepend)
	 * 
	 * EXAMPLE:
	 * 
	 * Parent layout:
	 *   <header>@section('header'):</header>
	 *   <main>@section('content'):</main>
	 * 
	 * Child sections:
	 *   ['header' => '<h1>My Header</h1>', 'content' => '<p>Hello</p>']
	 * 
	 * Result:
	 *   <header><h1>My Header</h1></header>
	 *   <main><p>Hello</p></main>
	 *
	 * @param  string  $layoutContent The raw parent layout content
	 * @param  array   $childSections Extracted sections from child
	 * @return string The layout with child sections injected
	 */
	protected function injectSectionsIntoLayout($layoutContent, $childSections)
	{
		// Process each section defined in the child
		foreach ($childSections as $name => $content) {
			
			// Handle sections that use @parent directive
			if (is_array($content) && isset($content['has_parent'])) {
				// Extract parent's default content for this section
				$parentContent = $this->extractParentSectionContent($layoutContent, $name);
				
				// Replace @parent with parent content
				// Only replace @parent that is NOT preceded by @ and IS followed by whitespace/end
				$childContent = preg_replace(
					'/(?<!@)@parent(?:\s|$)/',
					$parentContent . ' ',  // Add space to maintain formatting
					$content['content']
				);
				$content = $childContent;
			}
			
			// PATTERN 1: Replace empty placeholder @section('name'):
			// Must not be preceded by @ (respect @@section escaping)
			$pattern1 = '/(?<!@)@section\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*\):/';
			$layoutContent = preg_replace($pattern1, $content, $layoutContent);
			
			// PATTERN 2: Replace block section @section('name') ... @endsection
			// Both tags must not be preceded by @
			$pattern2 = '/(?<!@)@section\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*\).*?(?<!@)@endsection/s';
			$layoutContent = preg_replace($pattern2, $content, $layoutContent);
		}
		
		// Clean up: Remove remaining empty placeholders (but not escaped ones)
		$layoutContent = preg_replace('/(?<!@)@section\(\s*[\'"][^\'"]+[\'"]\s*\):/', '', $layoutContent);
		
		// Clean up: Keep default content for undefined sections
		$layoutContent = preg_replace_callback(
			'/(?<!@)@section\(\s*[\'"]([^\'"]+)[\'"]\s*\)(.*?)(?<!@)@endsection/s',
			function($matches) {
				return trim($matches[2]);
			},
			$layoutContent
		);
		
		return $layoutContent;

	}

	/**
	 * Extract parent's default content for a section (for @parent directive).
	 * 
	 * When a child section contains @parent, we need to get the parent's
	 * original content for that section and inject it where @parent appears.
	 * 
	 * This enables APPEND and PREPEND operations:
	 * 
	 * PREPEND (parent first, then child):
	 *   @section('scripts')
	 *     @parent
	 *     <script src="child.js"></script>
	 *   @endsection
	 * 
	 * APPEND (child first, then parent):
	 *   @section('scripts')
	 *     <script src="child.js"></script>
	 *     @parent
	 *   @endsection
	 * 
	 * EXAMPLE:
	 * 
	 * Parent layout has:
	 *   @section('scripts')
	 *     <script src="parent.js"></script>
	 *   @endsection
	 * 
	 * This method returns: '<script src="parent.js"></script>'
	 *
	 * @param  string  $layoutContent The parent layout raw content
	 * @param  string  $sectionName The section name to extract
	 * @return string The parent's content for this section, or empty string
	 */
	protected function extractParentSectionContent($layoutContent, $sectionName)
	{
		// Look for block section in parent (not escaped with @@)
		$pattern = '/(?<!@)@section\(\s*[\'"]' . preg_quote($sectionName, '/') . '[\'"]\s*\)(.*?)(?<!@)@endsection/s';
		
		if (preg_match($pattern, $layoutContent, $match)) {
			return trim($match[1]);
		}
		
		return '';

	}

	/**
	 * Compile the given template string.
	 * This is where the REAL MAGIC happens!
	 *
	 * PROCESS:
	 * 1. Use PHP's token_get_all() to break the template into tokens
	 * 2. Loop through each token
	 * 3. If it's HTML (T_INLINE_HTML), parse it for our directives
	 * 4. If it's already PHP code, leave it alone
	 * 5. Return the compiled result
	 *
	 * WHY token_get_all()?
	 * This allows us to distinguish between:
	 * - Raw HTML/template syntax (which we need to parse)
	 * - Existing PHP code (which we leave untouched)
	 *
	 * @param  string  $string The raw template content
	 * @return string The compiled PHP code
	 */
	public function compileString($string)
	{
		$result = '';

		// token_get_all() breaks the string into PHP tokens
		// Each token is either:
		// - An array: [token_type, token_content, line_number]
		// - A string: Single characters like ;, {, }, etc.
		foreach (token_get_all($string) as $token)
		{
			// If $token is an array, it's a proper PHP token - parse it
			// If $token is a string, it's a single character - just append it
			$result .= is_array($token) ? $this->parseToken($token) : $token;
		}

		return $result;
	}

	/**
	 * Parse individual tokens from the template.
	 * 
	 * PROCESS:
	 * 1. Extract the token ID and content
	 * 2. Check if it's T_INLINE_HTML (raw HTML/template syntax)
	 * 3. If yes, run ALL compilers on it (Statements, then Echos)
	 * 4. If no, return the content unchanged (it's already valid PHP)
	 *
	 * WHY only T_INLINE_HTML?
	 * Because that's the ONLY token type that contains our template syntax
	 * Everything else is already valid PHP code that should be left alone
	 *
	 * @param  array  $token Format: [token_id, content, line_number]
	 * @return string The compiled content for this token
	 */
	protected function parseToken($token)
	{
		// Extract token ID and content
		// Example: [T_INLINE_HTML, '<div>@if($user) Hello @endif</div>', 5]
		list($id, $content) = $token;

		// Only process HTML tokens (where our template syntax lives)
		// T_INLINE_HTML = any text that's not valid PHP
		if ($id == T_INLINE_HTML)
		{
			// Run EACH compiler in order on this content
			// First: compileStatements() - handles @if, @foreach, etc
			// Then: compileEchos() - handles {{ }} and {{{ }}}
			foreach ($this->compilers as $type)
			{
				// Dynamically call: compileStatements() or compileEchos()
				// $type = 'Statements' → calls $this->compileStatements($content)
				$content = $this->{"compile{$type}"}($content);
			}
		}

		return $content;
	}

	/**
	 * Compile echo statements into valid PHP.
	 * Handles BOTH {{ }} (escaped) and {{{ }}} (raw)
	 *
	 * PROCESS:
	 * There's a trick here to handle NESTED echo tags properly
	 * If {{ is longer than {{{, compile escaped echos first
	 * Otherwise, compile raw echos first
	 *
	 * NOTE: In current setup:
	 * {{ = 2 chars, {{{ = 3 chars
	 * So difference = 2 - 3 = -1 (negative)
	 * This means ESCAPED echos are compiled AFTER raw echos
	 *
	 * WHY? To avoid conflicts when one tag is a subset of another
	 *
	 * @param  string  $value The template content to process
	 * @return string Content with echo tags converted to PHP
	 */
	protected function compileEchos($value)
	{
		// Calculate length difference between the two echo tag types
		$difference = strlen($this->echoTags[0]) - strlen($this->rawEchoTags[0]);

		// If escaped tag is LONGER, do it first (to avoid matching issues)
		if ($difference > 0)
		{
			return $this->compileRawEchos($this->compileEscapedEchos($value));
		}

		// Otherwise, do raw first (this is the current case)
		return $this->compileEscapedEchos($this->compileRawEchos($value));
	}

	/**
	 * Compile directive statements that start with "@"
	 * Examples: @if, @foreach, @include, @extends, @section, etc
	 * 
	 * ESCAPING: Use @@ to output a literal @ symbol
	 * Example: @@if($test) → outputs: @if($test) literally
	 *
	 * PROCESS:
	 * 1. Use regex to find patterns like: @directive or @directive(params)
	 * 2. Check if first @ is escaped with another @ (@@directive)
	 * 3. If escaped, return literal @directive
	 * 4. If not escaped, check for special @section('name'): syntax (empty placeholder)
	 * 5. If not special, check if a method exists to handle this directive
	 * 6. If yes, call that method and replace the directive with compiled PHP
	 * 7. If no, leave it unchanged
	 *
	 * REGEX BREAKDOWN: /(@)?\B@(\w+)([ \t]*)(\( ( (?>[^()]+) | (?4) )* \))?(:)?/x
	 * - (@)? = optional @ before the directive (for escaping)
	 * - \B = word boundary (ensures @ is not part of another word)
	 * - @ = literal @ character for the directive
	 * - (\w+) = directive name (captured in $match[2])
	 * - ([ \t]*) = optional spaces/tabs (captured in $match[3])
	 * - (\( ... \))? = optional parentheses with params (captured in $match[4])
	 * - (:)? = optional colon after params (captured in $match[5]) - for @section('name'):
	 * - The inner regex handles NESTED parentheses properly
	 *
	 * @param  string  $value The template content
	 * @return string Content with directives converted to PHP
	 */
	protected function compileStatements($value)
	{
		// Define a callback function to handle each matched directive
		$callback = function($match)
		{
			// $match[0] = full match: "@if($user)" or "@@if($user)" or "@section('name'):"
			// $match[1] = optional escaping @: "@" or empty
			// $match[2] = directive name: "if", "section", etc
			// $match[3] = whitespace
			// $match[4] = parameters: "($user)" or "('name')"
			// $match[5] = optional colon: ":" (for @section('name'):)

			// Handle escaped directives (@@)
			// If there's an @ before the directive, output literal @directive
			if (isset($match[1]) && $match[1] === '@')
			{
				// Return the directive without the first @
				// @@if($x) → @if($x)
				return substr($match[0], 1);
			}

			// SPECIAL HANDLING for @section('name'): (empty placeholder with colon)
			// This is different from block sections @section('name')...@endsection
			// The colon indicates: "this is a placeholder, no closing tag needed"
			if ($match[2] === 'section' && isset($match[5]) && $match[5] === ':')
			{
				// Call special compiler for empty placeholder sections
				return $this->compileEmptySection(isset($match[4]) ? $match[4] : '');
			}

			// Check if a compile method exists for this directive
			// Example: if directive is "if", check for method "compileIf"
			if (method_exists($this, $method = 'compile'.ucfirst($match[2])))
			{
				// Call the appropriate compile method
				// Pass the parameters (without @directive part)
				// Example: compileIf('($user)')
				$match[0] = $this->$method(isset($match[4]) ? $match[4] : '');
			}

			// If parameters exist, return just the compiled code
			// If no parameters (like @else), return compiled code + whitespace
			return isset($match[4]) ? $match[0] : $match[0].$match[3];
		};

		// Run the regex replacement with our callback
		// Updated regex to capture optional @ before @directive for escaping
		// AND optional colon after params for @section('name'): syntax
		return preg_replace_callback('/(@)?\B@(\w+)([ \t]*)(\( ( (?>[^()]+) | (?4) )* \))?(:)?/x', $callback, $value);
	}

	/**
	 * Compile the ESCAPED echo statements (DEFAULT - secure by default).
	 * Converts: {{ $variable }} → <?php echo HTML::escape($variable); ?>
	 * 
	 * This is the SAFE default - protects against XSS attacks
	 * 
	 * ESCAPING: Use @{{ to output literal {{ }}
	 * Example: @{{ $var }} → {{ $var }} (not compiled)
	 *
	 * REGEX PATTERN: /(@)?{{\s*(.+?)\s*}}(\r?\n)?/s
	 * - (@)? = optional @ before {{ (for escaping: @{{ }})
	 * - {{ = opening tag
	 * - \s* = optional whitespace
	 * - (.+?) = content (non-greedy)
	 * - \s* = optional whitespace
	 * - }} = closing tag
	 * - (\r?\n)? = optional newline after (to preserve formatting)
	 *
	 * @param  string  $value Template content
	 * @return string Content with {{ }} converted to escaped echo
	 */
	protected function compileEscapedEchos($value)
	{
		// Build the regex pattern using configured tags
		$pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', preg_quote($this->echoTags[0]), preg_quote($this->echoTags[1]));

		$callback = function($matches)
		{
			// Preserve double newlines if there was a trailing newline
			$whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

			// If there's @ before {{, it's escaped - return literal {{ }}
			// Example: @{{ $var }} → {{ $var }}
			// Otherwise, compile to PHP echo with HTML escaping
			return $matches[1] ? substr($matches[0], 1) : '<?php echo HTML::escape('.$this->compileEchoDefaults($matches[2]).'); ?>'.$whitespace;
		};

		return preg_replace_callback($pattern, $callback, $value);
	}

	/**
	 * Compile the RAW/UNESCAPED echo statements.
	 * Converts: {{{ $html }}} → <?php echo $html; ?>
	 * 
	 * WARNING: This outputs raw HTML with NO escaping!
	 * Only use for trusted content that you WANT to render as HTML
	 * Using this with user input can cause XSS vulnerabilities
	 *
	 * @param  string  $value Template content
	 * @return string Content with {{{ }}} converted to raw echo
	 */
	protected function compileRawEchos($value)
	{
		// Build regex pattern using raw echo tags
		$pattern = sprintf('/%s\s*(.+?)\s*%s(\r?\n)?/s', preg_quote($this->rawEchoTags[0]), preg_quote($this->rawEchoTags[1]));

		$callback = function($matches)
		{
			// Preserve double newlines if present
			$whitespace = empty($matches[2]) ? '' : $matches[2].$matches[2];

			// No HTML::escape() - output raw!
			return '<?php echo '.$this->compileEchoDefaults($matches[1]).'; ?>'.$whitespace;
		};

		return preg_replace_callback($pattern, $callback, $value);
	}

	/**
	 * Compile default values for echo statements.
	 * Handles the "or" syntax for default values
	 * 
	 * Converts: {{ $name or 'Guest' }} → <?php echo isset($name) ? $name : 'Guest'; ?>
	 *
	 * REGEX: /^(?=\$)(.+?)(?:\s+or\s+)(.+?)$/s
	 * - ^(?=\$) = must start with $ (lookahead)
	 * - (.+?) = variable part (non-greedy)
	 * - (?:\s+or\s+) = literal " or " (non-capturing)
	 * - (.+?)$ = default value part
	 *
	 * @param  string  $value The content inside {{ }}
	 * @return string Content with "or" converted to ternary operator
	 */
	public function compileEchoDefaults($value)
	{
		// If pattern matches, replace with ternary
		// $name or 'Guest' → isset($name) ? $name : 'Guest'
		return preg_replace('/^(?=\$)(.+?)(?:\s+or\s+)(.+?)$/s', 'isset($1) ? $1 : $2', $value);
	}

	/**
	 * Compile @else statements.
	 * Converts: @else → <?php else: ?>
	 *
	 * @param  string  $expression (not used, @else takes no parameters)
	 * @return string
	 */
	protected function compileElse($expression)
	{
		return "<?php else: ?>";
	}

	/**
	 * Compile @for loops.
	 * Converts: @for($i = 0; $i < 10; $i++) → <?php for($i = 0; $i < 10; $i++): ?>
	 *
	 * @param  string  $expression The for loop parameters
	 * @return string
	 */
	protected function compileFor($expression)
	{
		return "<?php for{$expression}: ?>";
	}

	/**
	 * Compile @foreach loops.
	 * Converts: @foreach($users as $user) → <?php foreach($users as $user): ?>
	 *
	 * @param  string  $expression The foreach parameters
	 * @return string
	 */
	protected function compileForeach($expression)
	{
		return "<?php foreach{$expression}: ?>";
	}

	/**
	 * Compile @loopelse statements.
	 * This is a SPECIAL Rachie directive that combines foreach + else
	 * 
	 * Example:
	 * @loopelse($users as $user)
	 *     <p>{{ $user->name }}</p>
	 * @empty
	 *     <p>No users found</p>
	 * @endloop
	 *
	 * PROCESS:
	 * 1. Create a unique variable name ($__noitems_1, $__noitems_2, etc)
	 * 2. Set it to true before the loop
	 * 3. Set it to false if loop executes
	 * 4. @empty checks if it's still true (loop never ran)
	 *
	 * @param  string  $expression The foreach parameters
	 * @return string
	 */
	protected function compileLoopelse($expression)
	{
		// Increment counter to ensure unique variable names for nested loops
		$noitems = '$__noitems_' . ++$this->loopelseCounter;

		// Set flag to true, run foreach, set flag to false inside loop
		return "<?php {$noitems} = true; foreach{$expression}: {$noitems} = false; ?>";
	}

	/**
	 * Compile @if statements.
	 * Converts: @if($user) → <?php if($user): ?>
	 *
	 * @param  string  $expression The if condition
	 * @return string
	 */
	protected function compileIf($expression)
	{
		return "<?php if{$expression}: ?>";
	}

	/**
	 * Compile @elseif statements.
	 * Converts: @elseif($admin) → <?php elseif($admin): ?>
	 *
	 * @param  string  $expression The elseif condition
	 * @return string
	 */
	protected function compileElseif($expression)
	{
		return "<?php elseif{$expression}: ?>";
	}

	/**
	 * Compile @empty statements (used with @loopelse).
	 * This is the ELSE part of the @loopelse directive
	 *
	 * PROCESS:
	 * 1. Get the current loopelse counter value
	 * 2. Decrement it (we're closing this loopelse block)
	 * 3. End the foreach and check if the empty flag is still true
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEmpty($expression)
	{
		// Get current counter, then decrement for next level
		$noitems = '$__noitems_' . $this->loopelseCounter--;

		// End foreach, then check if loop never executed
		return "<?php endforeach; if ({$noitems}): ?>";
	}

	/**
	 * Compile @while loops.
	 * Converts: @while($condition) → <?php while($condition): ?>
	 *
	 * @param  string  $expression The while condition
	 * @return string
	 */
	protected function compileWhile($expression)
	{
		return "<?php while{$expression}: ?>";
	}

	/**
	 * Compile @endwhile statements.
	 * Converts: @endwhile → <?php endwhile; ?>
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEndwhile($expression)
	{
		return "<?php endwhile; ?>";
	}

	/**
	 * Compile @endfor statements.
	 * Converts: @endfor → <?php endfor; ?>
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEndfor($expression)
	{
		return "<?php endfor; ?>";
	}

	/**
	 * Compile @endforeach statements.
	 * Converts: @endforeach → <?php endforeach; ?>
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEndforeach($expression)
	{
		return "<?php endforeach; ?>";
	}

	/**
	 * Compile @endif statements.
	 * Converts: @endif → <?php endif; ?>
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEndif($expression)
	{
		return "<?php endif; ?>";
	}

	/**
	 * Compile @endloop statements.
	 * Closes the @loopelse / @empty block
	 * Converts: @endloop → <?php endif; ?>
	 *
	 * @param  string  $expression (not used)
	 * @return string
	 */
	protected function compileEndloop($expression)
	{
		return "<?php endif; ?>";
	}

	/**
	 * Compile @include statements.
	 * Allows including another template file inline
	 *
	 * PROCESS:
	 * 1. Extract the filename from the expression
	 * 2. Call View::getContents() to compile that file
	 * 3. Return the compiled content to be inserted inline
	 *
	 * Example: @include('partials/header')
	 * Result: The compiled contents of partials/header
	 *
	 * NOTE: This does INLINE inclusion (content is inserted during compilation)
	 * NOT runtime inclusion (like PHP's include statement)
	 *
	 * @param  string  $pathExpression Example: "('partials/header')"
	 * @return string The compiled content of the included file
	 */
	protected function compileInclude($pathExpression)
	{
		// Extract the filename from the expression
		// Input: "('partials/header')" → Output: "partials/header"
		// This finds the content between the first ' and the last '
		$fileName = substr($pathExpression, strpos($pathExpression, '\'') + 1, -(strlen($pathExpression) - strripos($pathExpression, '\'')));

		// Get the compiled contents of the included file
		// The TRUE parameter indicates this is an embedded/included view
		$includeContent = View::getContents($fileName, true);

		return $includeContent;
	}

}