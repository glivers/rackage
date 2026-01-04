<?php namespace Rackage;

/**
 * Base Model Class
 *
 * All application models extend this base class.
 * Provides a fluent query builder interface for database operations.
 *
 * Hybrid Design:
 *   Entry point methods are static for convenient syntax.
 *   Internally creates instances to isolate query state per call.
 *   This prevents state pollution between different model queries.
 *
 * Access Pattern: 
 *
 *   In Application Models:
 *     <?php namespace Models;
 *
 *     use Rackage\Model;
 *
 *     class PostsModel extends Model {
 *         protected static $table = 'posts';
 *         protected static $timestamps = true;
 *     }
 *
 *   In Controllers:
 *     use Models\Posts;
 *
 *     $posts = Posts::where('status', 'published')
 *                   ->order('created_at', 'desc')
 *                   ->limit(10)
 *                   ->all();
 *
 * Query Builder Pattern:
 *   Methods are chainable for building complex queries:
 *
 *   Posts::select(['id', 'title'])
 *        ->where('status', 'published')
 *        ->where('views > ?', 100)
 *        ->order('created_at', 'desc')
 *        ->limit(20)
 *        ->all();
 *
 * Method Categories:
 *
 *   QUERY BUILDERS (Chainable):
 *   - select($fields)           Select specific columns
 *   - where(...$args)           WHERE conditions
 *   - leftJoin($table, ...)     LEFT JOIN tables
 *   - order($field, $dir)       ORDER BY
 *   - limit($limit, $page)      LIMIT with pagination
 *   - unique()                  DISTINCT results
 *
 *   EXECUTION (Terminal):
 *   - all()                     Get all results
 *   - first()                   Get first result
 *   - count()                   Count results
 *
 *   SAVE/UPDATE:
 *   - save($data)               Insert or update
 *   - saveBulk($data, ...)      Bulk insert/update
 *   - saveById($data)           Update by ID
 *
 *   DELETE:
 *   - delete()                  Delete matching records
 *   - deleteById($id)           Delete by ID
 *
 *   CONVENIENCE:
 *   - getById($id)              Get by ID
 *   - getByDateCreated($date)   Get by date_created
 *   - getByDateModified($date)  Get by date_modified
 *
 *   RAW SQL:
 *   - sql($sql, ...$params)     Execute raw SQL with optional binding
 *
 * Security:
 *   - All queries use PDO prepared statements
 *   - Automatic parameter binding prevents SQL injection
 *   - Never concatenate user input into SQL
 *
 * Timestamps:
 *   Set protected static $timestamps = true in your model
 *   to automatically manage date_created and date_modified columns.
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Model
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.2
 */

use Rackage\Registry;
use Rackage\ModelException;

class Model
{

	/**
	 * Constructor
	 *
	 * Models can be instantiated internally for query chaining.
	 * Each query chain gets its own instance with isolated state.
	 *
	 * @return void
	 */
	public function __construct() {}

	/**
	 * Database connection instance
	 * @var object
	 */
	protected static $connection;

	/**
	 * Table name (overridden by child classes)
	 * @var string|null
	 */
	protected static $table = null;

	/**
	 * Enable/disable automatic timestamp management (overridden by child classes)
	 * @var bool
	 */
	protected static $timestamps = false;

	/**
	 * Query builder instance (per model instance)
	 * @var object
	 */
	protected $queryObject;

	/**
	 * Tracks if query table has been set
	 * @var bool
	 */
	protected static $queryTableSet = false;

	/**
	 * Tracks if database connection has been made
	 * @var bool
	 */
	protected static $dbConnectionMade = false;

	// =========================================================================
	// INTERNAL METHODS
	// =========================================================================

	/**
	 * Get query builder instance
	 *
	 * Lazy-loads database connection and query builder for this model instance.
	 * Each instance has its own query builder to prevent state pollution.
	 *
	 * @return object Query builder instance
	 */
	protected function Query()
	{
		// Get connection if not set (shared across all models)
		if(static::$connection === null) static::$connection = Registry::get('database');

		// Get query builder for this instance (unique per model call)
		if($this->queryObject === null) $this->queryObject = static::$connection->query(static::$table, static::$timestamps);

		return $this->queryObject;
	}

	/**
	 * Set table name for query
	 *
	 * Internal method called before query execution.
	 *
	 * @return void
	 */
	final private function setTable()
	{
		$this->Query()->setTable(static::$table);
	}

	// =========================================================================
	// QUERY BUILDER METHODS (Chainable)
	// =========================================================================

	/**
	 * Select specific columns
	 *
	 * Specify which columns to retrieve.
	 * Chainable with other query methods.
	 *
	 * Examples:
	 *   // Select all columns (default)
	 *   Posts::select()->all();
	 *
	 *   // Select specific columns
	 *   Posts::select(['id', 'title', 'created_at'])->all();
	 *
	 *   // Select with other conditions
	 *   Users::select(['id', 'name', 'email'])
	 *        ->where('status = ?', 'active')
	 *        ->all();
	 *
	 * @param array $fields Column names to select (default: all columns)
	 * @return static Returns model instance for chaining
	 */
	final public static function select($fields = array("*"))
	{
		$instance = new static;

		return $instance->Query()->setFields(static::$table, $fields);
	}

	/**
	 * Enable SQL debug mode
	 *
	 * Returns SQL query string instead of executing.
	 * Use for debugging query construction.
	 *
	 * Examples:
	 *   // Debug SELECT query
	 *   $sql = Posts::toSql()->where('status', 'published')->all();
	 *   echo $sql;  // "SELECT posts.* FROM posts WHERE status = 'published'"
	 *
	 *   // Debug UPDATE query
	 *   $sql = Posts::toSql()->where('id', 5)->save(['status' => 'draft']);
	 *   echo $sql;  // "UPDATE posts SET status = 'draft' WHERE id = 5"
	 *
	 *   // Debug INSERT query
	 *   $sql = Posts::toSql()->save(['title' => 'New Post']);
	 *   echo $sql;  // "INSERT INTO posts (title) VALUES ('New Post')"
	 *
	 * @return static Returns query builder instance in SQL debug mode
	 */
	final public static function toSql()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->toSql();
	}

	/**
	 * Enable unbuffered query execution
	 *
	 * Streams results row-by-row instead of loading entire result set into memory.
	 * Essential for processing large datasets (millions of rows) without memory exhaustion.
	 *
	 * Memory comparison:
	 *   Buffered (default):  100M rows × 60 bytes = 6 GB in memory
	 *   Unbuffered:          1 row × 60 bytes = 60 bytes in memory at a time
	 *
	 * Use for:
	 *   - Large result sets (millions of rows)
	 *   - Building graphs/structures incrementally
	 *   - Export/backup operations
	 *   - Processing data that doesn't fit in memory
	 *
	 * Do NOT use for:
	 *   - Small result sets (< 10K rows) - buffered is faster
	 *   - When you need result count before processing
	 *   - Multiple concurrent queries on same connection
	 *
	 * Examples:
	 *   // PageRank: Process 100M links without loading all into memory
	 *   $result = LinkModel::noBuffer()->select(['source', 'target'])->all();
	 *   while ($row = $result->fetch_assoc()) {
	 *       // Build graph incrementally (only 1 row in memory at a time)
	 *       $graph['inbound'][$row['target']][] = $row['source'];
	 *   }
	 *
	 *   // Export: Stream large table to file
	 *   $result = UserModel::noBuffer()->select(['id', 'email'])->all();
	 *   while ($row = $result->fetch_assoc()) {
	 *       fputcsv($file, $row);
	 *   }
	 *
	 * IMPORTANT:
	 *   - Returns mysqli_result object, NOT array
	 *   - Use fetch_assoc() in while loop to iterate
	 *   - Cannot use result_array() (defeats the purpose)
	 *
	 * @return static Returns query builder instance in unbuffered mode
	 */
	final public static function noBuffer()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->noBuffer();
	}

	/**
	 * Enable async query execution
	 *
	 * When enabled, terminal methods (all, first, count, delete, save)
	 * fire the query with MYSQLI_ASYNC and return a Promise instead
	 * of blocking for the result.
	 *
	 * Call await() on the Promise to get the actual result.
	 *
	 * Limitations:
	 *   - One async query at a time per connection
	 *   - Must await() before starting another async query
	 *   - Cannot be combined with noBuffer()
	 *
	 * Examples:
	 *   // Fire query, do other work, then get result
	 *   $promise = PageModel::async()->where('status', 'active')->all();
	 *   $otherData = doSomethingElse();
	 *   $pages = $promise->await();
	 *
	 *   // Check if ready without blocking
	 *   if ($promise->ready()) {
	 *       $pages = $promise->await();
	 *   }
	 *
	 * @return static Returns query builder instance in async mode
	 */
	final public static function async()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->async();
	}

	/**
	 * LEFT JOIN another table
	 *
	 * Join related tables to retrieve associated data.
	 * Must be chained after an entry point method like select() or where().
	 *
	 * Examples:
	 *   // Join users table
	 *   Posts::select(['posts.*'])
	 *        ->leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *        ->all();
	 *
	 *   // Multiple joins
	 *   Posts::select(['posts.*'])
	 *        ->leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *        ->leftJoin('categories', 'posts.category_id = categories.id', ['categories.name'])
	 *        ->all();
	 *
	 * @param string $table Table name to join
	 * @param string $condition Join condition (e.g., 'posts.user_id = users.id')
	 * @param array $fields Columns to select from joined table
	 * @return $this Returns this instance for chaining
	 */
	final public static function leftJoin($table, $condition, $fields = array("*"))
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->leftJoin($table, $condition, $fields);
	}

	/**
	 * Limit number of results with pagination
	 *
	 * Limits query results and supports pagination.
	 * Must be chained after an entry point method like select() or where().
	 *
	 * Examples:
	 *   // Get first 10 results
	 *   Posts::select()->limit(10)->all();
	 *
	 *   // Pagination - 20 per page, page 1
	 *   Posts::select()->limit(20, 1)->all();
	 *
	 *   // Pagination - page 2
	 *   Posts::select()->limit(20, 2)->all();
	 *
	 *   // With other conditions
	 *   Posts::where('status = ?', 'published')
	 *        ->order('created_at', 'desc')
	 *        ->limit(10, $page)
	 *        ->all();
	 *
	 * @param int $limit Maximum number of rows to return
	 * @param int $page Page number for pagination (default: 1)
	 * @return $this Returns this instance for chaining
	 */
	final public function limit($limit, $page = 1)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->limit($limit, $page);
	}

	/**
	 * Get distinct/unique results
	 *
	 * Returns only unique values, eliminating duplicates.
	 * Must be chained after an entry point method like select().
	 *
	 * Examples:
	 *   // Get unique categories
	 *   Posts::select(['category'])->unique()->all();
	 *
	 *   // Get unique status values
	 *   Orders::select(['status'])->unique()->all();
	 *
	 * @return $this Returns this instance for chaining
	 */
	final public function unique()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->unique();
	}

	/**
	 * Order results by column
	 *
	 * Sorts query results in ascending or descending order.
	 *
	 * Examples:
	 *   // Order by created date, newest first
	 *   Posts::order('created_at', 'desc')->all();
	 *
	 *   // Order by title alphabetically
	 *   Posts::order('title', 'asc')->all();
	 *
	 *   // Multiple order criteria (chained)
	 *   Products::order('category', 'asc')
	 *           ->order('price', 'desc')
	 *           ->all();
	 *
	 * @param string $order Column name to sort by
	 * @param string $direction Sort direction: 'asc' or 'desc' (default: 'asc')
	 * @return static Returns new static instance for chaining
	 */
	final public static function order($order, $direction = 'asc')
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->order($order, $direction);		
	}

	/**
	 * Add WHERE conditions
	 *
	 * Filter results based on conditions.
	 * Accepts variadic arguments for flexible usage.
	 * Can be called as entry point or chained.
	 *
	 * Examples:
	 *   // Entry point - simple condition
	 *   Posts::where('status = ?', 'published')->all();
	 *
	 *   // With operator
	 *   Products::where('price > ?', 100)->all();
	 *
	 *   // Multiple conditions (AND)
	 *   Posts::where('status = ?', 'published')
	 *        ->where('views > ?', 1000)
	 *        ->all();
	 *
	 *   // Multiple placeholders
	 *   Orders::where('status = ? AND total > ?', 'paid', 100)->all();
	 *
	 * Security:
	 *   Always use placeholders (?) for values.
	 *   Never concatenate user input into WHERE clause.
	 *
	 * @param mixed ...$args Variadic arguments for WHERE conditions
	 * @return static Returns model instance for chaining
	 */
	final public static function where()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->where(func_get_args());		
	}

	// =========================================================================
	// SAVE/UPDATE METHODS
	// =========================================================================

	/**
	 * Insert or update record
	 *
	 * Inserts new record or updates existing record if WHERE clause is set.
	 *
	 * Examples:
	 *   // Insert new record
	 *   Posts::save([
	 *       'title' => 'New Post',
	 *       'content' => 'Post content',
	 *       'status' => 'draft'
	 *   ]);
	 *
	 *   // Update existing record
	 *   Posts::where('id', 123)->save(['title' => 'Updated Title']);
	 *
	 *   // Update multiple records
	 *   Posts::where('status', 'draft')->save(['status' => 'published']);
	 *
	 * Timestamps:
	 *   If $timestamps is true, automatically sets:
	 *   - date_created on insert
	 *   - date_modified on update
	 *
	 * @param array $data Associative array of column => value pairs
	 * @return MySQLResponse Response object with insert ID, affected rows, etc.
	 */
	final public static function save($data)
	{
		$instance = new static;
		$instance->setTable();

		// Execute insert or update
		return $instance->Query()->save($data, static::$timestamps);
	}

	/**
	 * Bulk insert or update records
	 *
	 * Insert or update multiple records in a single query.
	 * More efficient than multiple save() calls.
	 *
	 * Examples:
	 *   // Bulk insert
	 *   Posts::saveBulk(
	 *       [
	 *           ['title' => 'Post 1', 'content' => 'Content 1'],
	 *           ['title' => 'Post 2', 'content' => 'Content 2']
	 *       ],
	 *       ['title', 'content']
	 *   );
	 *
	 *   // Bulk update
	 *   Users::saveBulk(
	 *       [
	 *           ['id' => 1, 'status' => 'active'],
	 *           ['id' => 2, 'status' => 'inactive']
	 *       ],
	 *       ['status'],
	 *       ['id']
	 *   );
	 *
	 * @param array $data Multi-dimensional array of records
	 * @param array $fields Column names (optional)
	 * @param array $ids ID columns for updates (optional)
	 * @param mixed $key Primary key name (optional)
	 * @return MySQLResponse Response object
	 */
	final public static function saveBulk($data, $fields = null, $ids = null, $key = null)
	{
		$instance = new static;
		$instance->setTable();

		// Execute bulk insert or update
		return $instance->Query()->saveBulk($data, $fields, $ids, $key, static::$timestamps);
	}

	/**
	 * Bulk increment multiple rows with different values
	 *
	 * Efficiently increments fields for multiple records in a single query using CASE statements.
	 * Much faster than individual increment() calls in a loop.
	 *
	 * Examples:
	 *   // Increment single field
	 *   DomainModel::incrementBulk(
	 *       [
	 *           1 => ['total_pages' => 5],
	 *           2 => ['total_pages' => 3],
	 *           3 => ['total_pages' => 8]
	 *       ],
	 *       ['total_pages'],
	 *       [1, 2, 3],
	 *       'id'
	 *   );
	 *
	 *   // Increment multiple fields per row
	 *   DomainModel::incrementBulk(
	 *       [
	 *           1 => ['active_pages' => 5, 'failed_pages' => 2],
	 *           2 => ['active_pages' => 3]
	 *       ],
	 *       ['active_pages', 'failed_pages'],
	 *       [1, 2],
	 *       'id'
	 *   );
	 *
	 * @param array $data Data keyed by ID: [id => ['field' => incrementValue]]
	 * @param array $fields Array of field names to increment
	 * @param array $ids Array of IDs to update
	 * @param string $key Key column name (default: 'id')
	 * @return int Number of rows affected
	 */
	final public static function incrementBulk($data, $fields, $ids, $key = 'id')
	{
		$instance = new static;
		$instance->setTable();

		// Execute bulk increment
		return $instance->Query()->incrementBulk($data, $fields, $ids, $key);
	}

	/**
	 * Bulk decrement fields for multiple records
	 *
	 * Efficiently decrements fields for multiple records in a single query.
	 * Mirrors incrementBulk but subtracts values instead.
	 *
	 * Examples:
	 *   // Decrement active_pages for multiple domains
	 *   DomainModel::decrementBulk(
	 *       [
	 *           1 => ['active_pages' => 5],
	 *           2 => ['active_pages' => 3],
	 *       ],
	 *       ['active_pages'],
	 *       [1, 2],
	 *       'id'
	 *   );
	 *
	 * @param array $data Data keyed by ID: [id => ['field' => decrementValue]]
	 * @param array $fields Array of field names to decrement
	 * @param array $ids Array of IDs to update
	 * @param string $key Key column name (default: 'id')
	 * @return int Number of rows affected
	 */
	final public static function decrementBulk($data, $fields, $ids, $key = 'id')
	{
		$instance = new static;
		$instance->setTable();

		// Execute bulk decrement
		return $instance->Query()->decrementBulk($data, $fields, $ids, $key);
	}

	/**
	 * Insert with INSERT IGNORE (skip duplicates)
	 *
	 * Works with both single and bulk inserts. Uses INSERT IGNORE to silently skip
	 * duplicate key errors. Much faster than checking for duplicates before inserting.
	 * Returns the number of rows actually inserted (excludes skipped duplicates).
	 *
	 * Performance Benefit:
	 *   - Old: SELECT whereIn() + INSERT = 2 queries, slow with large datasets
	 *   - New: INSERT IGNORE = 1 query, fast even with duplicates
	 *
	 * Use Cases:
	 *   - When you have URLs to insert and don't care about duplicate errors
	 *   - When checking for duplicates beforehand is slower than letting DB handle it
	 *
	 * Examples:
	 *   // Single insert
	 *   $count = QueueModel::saveIgnore([
	 *       'url' => 'https://example.com',
	 *       'url_hash' => 'abc123',
	 *       'host' => 'example.com'
	 *   ]);
	 *
	 *   // Bulk insert (auto-detected)
	 *   $count = QueueModel::saveIgnore([
	 *       ['url' => 'https://example.com', 'url_hash' => 'abc123', ...],
	 *       ['url' => 'https://test.com', 'url_hash' => 'def456', ...],
	 *       ['url' => 'https://example.com', 'url_hash' => 'abc123', ...], // Duplicate, skipped
	 *   ]);
	 *   // $count = 2 (third row skipped)
	 *
	 * @param array $data Single record (associative array) or multiple records (array of arrays)
	 * @return int Number of rows actually inserted (excludes duplicates)
	 */
	final public static function saveIgnore($data)
	{
		$instance = new static;
		$instance->setTable();

		// Execute insert with IGNORE (auto-detects single vs bulk)
		return $instance->Query()->saveIgnore($data, static::$timestamps);
	}

	/**
	 * Insert or update on duplicate key (upsert)
	 *
	 * Works with both single and bulk inserts. Uses INSERT ... ON DUPLICATE KEY UPDATE
	 * to insert new rows or update existing ones when a duplicate key is encountered.
	 * Returns the number of affected rows (1 for insert, 2 for update, 0 for unchanged).
	 *
	 * Performance Benefit:
	 *   - Old: SELECT + INSERT or UPDATE = 2 queries
	 *   - New: INSERT ... ON DUPLICATE KEY UPDATE = 1 query
	 *
	 * Use Cases:
	 *   - Syncing data where you want to update existing records
	 *   - Counters that should be created or incremented
	 *   - Caching/storing data that may already exist
	 *
	 * Examples:
	 *   // Single upsert - insert or update all fields
	 *   PageModel::saveUpdate([
	 *       'url_hash' => 'abc123',
	 *       'url' => 'https://example.com',
	 *       'title' => 'Example',
	 *       'status' => 'active'
	 *   ]);
	 *
	 *   // Single upsert - only update specific fields on duplicate
	 *   PageModel::saveUpdate([
	 *       'url_hash' => 'abc123',
	 *       'url' => 'https://example.com',
	 *       'title' => 'Example',
	 *       'visit_count' => 1
	 *   ], ['title', 'visit_count']);
	 *
	 *   // Bulk upsert
	 *   PageModel::saveUpdate([
	 *       ['url_hash' => 'abc123', 'title' => 'Page 1', 'views' => 1],
	 *       ['url_hash' => 'def456', 'title' => 'Page 2', 'views' => 1],
	 *   ], ['title', 'views']);
	 *
	 * @param array $data Single record (associative array) or multiple records (array of arrays)
	 * @param array|null $fields Fields to update on duplicate (null = all fields)
	 * @return int Number of affected rows (1=insert, 2=update per row)
	 */
	final public static function saveUpdate($data, $fields = null)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->saveUpdate($data, $fields, static::$timestamps);
	}

	/**
	 * Update record by ID
	 *
	 * Convenience method to update a record by its ID.
	 * Expects 'id' key in data array.
	 *
	 * Examples:
	 *   Posts::saveById([
	 *       'id' => 123,
	 *       'title' => 'Updated Title',
	 *       'content' => 'Updated content'
	 *   ]);
	 *
	 * @param array $data Data array with 'id' key
	 * @return MySQLResponse Response object
	 * @throws ModelException If 'id' field not found or data is empty
	 */
	final public static function saveById($data)
	{
		$instance = new static;
		$instance->setTable();
		
		return $instance->Query()->saveById($data, static::$timestamps);
	}

	// =========================================================================
	// DELETE METHODS
	// =========================================================================

	/**
	 * Delete matching records
	 *
	 * Deletes records that match WHERE conditions.
	 *
	 * Examples:
	 *   // Delete by ID
	 *   Posts::where('id', 123)->delete();
	 *
	 *   // Delete multiple records
	 *   Posts::where('status', 'draft')->delete();
	 *
	 *   // Delete with complex conditions
	 *   Sessions::where('expires_at < ?', Date::now())->delete();
	 *
	 * WARNING: Without WHERE clause, deletes ALL records!
	 *
	 * @return MySQLResponse Response object with affected rows count
	 */
	final public static function delete()
	{
		$instance = new static;
		$instance->setTable();

		// Execute delete
		return $instance->Query()->delete();
	}

	/**
	 * Delete record by ID
	 *
	 * Convenience method to delete a single record by its ID.
	 *
	 * Examples:
	 *   Posts::deleteById(123);
	 *   Users::deleteById($userId);
	 *
	 * @param int $id Record ID to delete
	 * @return MySQLResponse Response object
	 */
	final public static function deleteById($id)
	{
		// Build WHERE clause for ID
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->where(array('id = ?', $id))->delete();
	}

	// =========================================================================
	// EXECUTION METHODS (Terminal)
	// =========================================================================

	/**
	 * Get first result
	 *
	 * Executes query and returns first matching row.
	 * Returns null if no results.
	 *
	 * Examples:
	 *   // Get single post
	 *   $post = Posts::where('slug', $slug)->first();
	 *
	 *   // Get user by email
	 *   $user = Users::where('email', $email)->first();
	 *
	 *   // With select
	 *   $user = Users::select(['id', 'name'])
	 *                ->where('id', $userId)
	 *                ->first();
	 *
	 * @return object|null First result or null
	 */
	final public static function first()
	{
		$instance = new static;
		$instance->setTable();

		// Execute query
		return $instance->Query()->first();
	}

	/**
	 * Count matching records
	 *
	 * Returns count of records matching query conditions.
	 *
	 * Examples:
	 *   // Count all posts
	 *   $total = Posts::count();
	 *
	 *   // Count published posts
	 *   $published = Posts::where('status', 'published')->count();
	 *
	 *   // Count active users
	 *   $active = Users::where('status', 'active')->count();
	 *
	 * @return int Number of matching records
	 */
	final public static function count()
	{
		$instance = new static;
		$instance->setTable();

		// Execute count query
		return $instance->Query()->count();
	}

	/**
	 * Get all results
	 *
	 * Executes query and returns all matching rows.
	 * Returns empty array if no results.
	 *
	 * Examples:
	 *   // Get all posts
	 *   $posts = Posts::all();
	 *
	 *   // Get filtered results
	 *   $posts = Posts::where('status', 'published')
	 *                 ->order('created_at', 'desc')
	 *                 ->limit(20)
	 *                 ->all();
	 *
	 *   // Get with joins
	 *   $posts = Posts::leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *                 ->all();
	 *
	 * @return array Array of result objects
	 */
	final public static function all()
	{
		$instance = new static;
		$instance->setTable();

		// Execute query
		return $instance->Query()->all();
	}

	// =========================================================================
	// CONVENIENCE METHODS
	// =========================================================================

	/**
	 * Get records by ID
	 *
	 * Convenience method to fetch records matching an ID.
	 *
	 * Examples:
	 *   $post = Posts::getById(123);
	 *   $user = Users::getById($userId);
	 *
	 * Note: Returns array of results (use first() for single object)
	 *
	 * @param int $id ID to search for
	 * @return array Array of matching records
	 */
	final public static function getById($id)
	{
		// Build WHERE clause for ID
		$instance = new static;
		$instance->setTable();
		
		return $instance->Query()->where(array('id = ?', $id))->all();
	}

	/**
	 * Get records by creation date
	 *
	 * Retrieves records created on a specific date.
	 * Requires date_created column.
	 *
	 * Examples:
	 *   $posts = Posts::getByDateCreated('2024-01-15');
	 *   $orders = Orders::getByDateCreated(Date::now('Y-m-d'));
	 *
	 * @param string $dateCreated Date string (Y-m-d format)
	 * @return array Array of matching records
	 */
	final public static function getByDateCreated($dateCreated)
	{
		// Build WHERE clause for date_created
		$instance = new static;
		$instance->setTable();
		
		return $instance->Query()->where(array('date_created = ?', $dateCreated))->all();
	}

	/**
	 * Get records by modification date
	 *
	 * Retrieves records modified on a specific date.
	 * Requires date_modified column.
	 *
	 * Examples:
	 *   $posts = Posts::getByDateModified('2024-01-15');
	 *   $updated = Products::getByDateModified(Date::now('Y-m-d'));
	 *
	 * @param string $dateModified Date string (Y-m-d format)
	 * @return array Array of matching records
	 */
	final public static function getByDateModified($dateModified)
	{
		// Build WHERE clause for date_modified
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->where(array('date_modified = ?', $dateModified))->all();
	}

	// =========================================================================
	// RAW SQL
	// =========================================================================

	/**
	 * Execute raw SQL query with optional parameter binding
	 *
	 * Executes a raw SQL query directly on the database.
	 * Supports optional parameter binding for safe value injection.
	 * Chain with noBuffer() for memory-efficient large result sets.
	 *
	 * Parameter Binding:
	 *   Use ? as placeholders, pass values as additional arguments.
	 *   Values are automatically escaped for security.
	 *
	 * Examples:
	 *   // Simple query
	 *   Posts::sql('SELECT * FROM posts')
	 *
	 *   // With parameter binding
	 *   Posts::sql('SELECT * FROM users WHERE age > ? AND status = ?', 18, 'active')
	 *   → SELECT * FROM users WHERE age > 18 AND status = 'active'
	 *
	 *   // Unbuffered for large result sets
	 *   Posts::noBuffer()->sql('SELECT * FROM large_table')
	 *
	 * @param string $query SQL query (with ? placeholders if binding)
	 * @param mixed ...$params Values to bind to placeholders
	 * @return mixed Query result
	 * @throws DatabaseException If query fails or param count mismatch
	 */
	final public static function sql($query, ...$params)
	{
		$instance = new static;
		return $instance->Query()->sql($query, ...$params);
	}

	// =========================================================================
	// CORE 12 FEATURES - Advanced Query Builder Methods
	// =========================================================================

	/**
	 * Add GROUP BY clause
	 *
	 * Groups results by one or more columns.
	 * Typically used with aggregate functions (COUNT, SUM, AVG, etc.).
	 *
	 * Examples:
	 *   Posts::groupBy('category')->all();
	 *   Posts::groupBy('year', 'month')->all();
	 *
	 * @param string ...$columns Column names to group by
	 * @return object Query instance (chainable)
	 */
	final public static function groupBy(...$columns)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->groupBy(...$columns);
	}

	/**
	 * Add HAVING clause
	 *
	 * Filters grouped results (like WHERE but for GROUP BY).
	 * Must be used with groupBy().
	 *
	 * Examples:
	 *   Posts::groupBy('author_id')->having('COUNT(*) > ?', 5)->all();
	 *
	 * @param mixed ...$arguments Variadic arguments (condition, value pairs)
	 * @return object Query instance (chainable)
	 */
	final public static function having(...$arguments)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->having(...$arguments);
	}

	/**
	 * Add INNER JOIN clause
	 *
	 * Joins another table and returns only matching records from both tables.
	 *
	 * Examples:
	 *   Posts::innerJoin('users', 'posts.user_id = users.id', ['users.name'])->all();
	 *
	 * @param string $table Table to join
	 * @param string $condition Join condition
	 * @param array $fields Fields to select from joined table
	 * @return object Query instance (chainable)
	 */
	final public static function innerJoin($table, $condition, $fields = array("*"))
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->innerJoin($table, $condition, $fields);
	}

	/**
	 * Add OR WHERE clause
	 *
	 * Adds WHERE conditions with OR operator instead of AND.
	 *
	 * Examples:
	 *   Posts::where('status', 'published')->orWhere('status', 'featured')->all();
	 *
	 * @param mixed ...$arguments Variadic arguments (condition, value pairs)
	 * @return object Query instance (chainable)
	 */
	final public static function orWhere(...$arguments)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->orWhere(...$arguments);
	}

	/**
	 * Add WHERE IN clause
	 *
	 * Checks if column value matches any value in an array.
	 *
	 * Examples:
	 *   Posts::whereIn('id', [1, 2, 3, 4, 5])->all();
	 *   Posts::whereIn('status', ['draft', 'pending'])->all();
	 *
	 * @param string $column Column name
	 * @param array $values Array of values to match
	 * @return object Query instance (chainable)
	 */
	final public static function whereIn($column, $values)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereIn($column, $values);
	}

	/**
	 * Add WHERE NOT IN clause
	 *
	 * Checks if column value does NOT match any value in an array.
	 *
	 * Examples:
	 *   Posts::whereNotIn('status', ['deleted', 'banned'])->all();
	 *
	 * @param string $column Column name
	 * @param array $values Array of values to exclude
	 * @return object Query instance (chainable)
	 */
	final public static function whereNotIn($column, $values)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereNotIn($column, $values);
	}

	/**
	 * Add WHERE BETWEEN clause
	 *
	 * Checks if column value falls within a range (inclusive).
	 *
	 * Examples:
	 *   Posts::whereBetween('age', 18, 65)->all();
	 *   Posts::whereBetween('created_at', '2024-01-01', '2024-12-31')->all();
	 *
	 * @param string $column Column name
	 * @param mixed $min Minimum value (inclusive)
	 * @param mixed $max Maximum value (inclusive)
	 * @return object Query instance (chainable)
	 */
	final public static function whereBetween($column, $min, $max)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereBetween($column, $min, $max);
	}

	/**
	 * Add WHERE LIKE clause
	 *
	 * Performs pattern matching on column values.
	 *
	 * Examples:
	 *   Posts::whereLike('title', 'WordPress%')->all();
	 *   Posts::whereLike('email', '%@gmail.com')->all();
	 *
	 * @param string $column Column name
	 * @param string $pattern Pattern to match (with % or _ wildcards)
	 * @return object Query instance (chainable)
	 */
	final public static function whereLike($column, $pattern)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereLike($column, $pattern);
	}

	/**
	 * Add WHERE NOT LIKE clause
	 *
	 * Performs pattern exclusion on column values.
	 *
	 * Examples:
	 *   Posts::whereNotLike('email', '%spam.com')->all();
	 *
	 * @param string $column Column name
	 * @param string $pattern Pattern to exclude
	 * @return object Query instance (chainable)
	 */
	final public static function whereNotLike($column, $pattern)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereNotLike($column, $pattern);
	}

	/**
	 * Add WHERE NULL clause
	 *
	 * Checks if column value is NULL.
	 *
	 * Examples:
	 *   Posts::whereNull('deleted_at')->all();
	 *
	 * @param string $column Column name
	 * @return object Query instance (chainable)
	 */
	final public static function whereNull($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereNull($column);
	}

	/**
	 * Add WHERE NOT NULL clause
	 *
	 * Checks if column value is NOT NULL.
	 *
	 * Examples:
	 *   Posts::whereNotNull('published_at')->all();
	 *
	 * @param string $column Column name
	 * @return object Query instance (chainable)
	 */
	final public static function whereNotNull($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereNotNull($column);
	}

	/**
	 * Increment a column value
	 *
	 * Increases a numeric column by specified amount (default: 1).
	 *
	 * Examples:
	 *   Posts::where('id', 123)->increment('views');
	 *   Posts::where('id', 456)->increment('votes', 5);
	 *
	 * @param string $column Column name to increment
	 * @param int $amount Amount to increment by (default: 1)
	 * @return MySQLResponse Query execution result
	 */
	final public static function increment($column, $amount = 1)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->increment($column, $amount);
	}

	/**
	 * Decrement a column value
	 *
	 * Decreases a numeric column by specified amount (default: 1).
	 *
	 * Examples:
	 *   Posts::where('id', 123)->decrement('stock');
	 *   Posts::where('id', 456)->decrement('credits', 10);
	 *
	 * @param string $column Column name to decrement
	 * @param int $amount Amount to decrement by (default: 1)
	 * @return MySQLResponse Query execution result
	 */
	final public static function decrement($column, $amount = 1)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->decrement($column, $amount);
	}

	/**
	 * Add FULLTEXT search clause
	 *
	 * Performs full-text search on columns with FULLTEXT index.
	 *
	 * Examples:
	 *   Posts::whereFulltext(['title', 'content'], 'WordPress tutorial')->all();
	 *   Posts::whereFulltext(['body'], '+MySQL -Oracle', 'boolean')->all();
	 *
	 * @param array $columns Array of column names with FULLTEXT index
	 * @param string $search Search query string
	 * @param string $mode Search mode: 'natural', 'boolean', 'expansion'
	 * @return object Query instance (chainable)
	 */
	final public static function whereFulltext($columns, $search, $mode = 'natural')
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereFulltext($columns, $search, $mode);
	}

	/**
	 * Begin database transaction
	 *
	 * Starts a transaction to group multiple queries atomically.
	 *
	 * Example:
	 *   Posts::begin();
	 *   Posts::save(['title' => 'New Post']);
	 *   Posts::commit(); // or Posts::rollback();
	 *
	 * @return bool True on success
	 */
	final public static function begin()
	{
		$instance = new static;

		return $instance->Query()->begin();
	}

	/**
	 * Commit database transaction
	 *
	 * Saves all changes made during the transaction.
	 *
	 * Example:
	 *   Posts::begin();
	 *   // ... multiple queries ...
	 *   Posts::commit();
	 *
	 * @return bool True on success
	 */
	final public static function commit()
	{
		$instance = new static;

		return $instance->Query()->commit();
	}

	/**
	 * Rollback database transaction
	 *
	 * Cancels all changes made during the transaction.
	 *  
	 * Example:
	 *   Posts::begin();
	 *   try {
	 *       // ... queries ...
	 *       Posts::commit();
	 *   } catch (Exception $e) {
	 *       Posts::rollback();
	 *   }
	 *
	 * @return bool True on success
	 */
	final public static function rollback()
	{
		$instance = new static;

		return $instance->Query()->rollback();
	}

	/**
	 * Add row-level UPDATE lock (FOR UPDATE)
	 *
	 * Applies exclusive lock on selected rows within a transaction.
	 * Must be chained before all() or first() execution methods.
	 *
	 * Examples:
	 *   QueueModel::where('status', 'pending')
	 *             ->updateLock('skip')
	 *             ->all();
	 *
	 *   Posts::where('id', 5)->updateLock()->first();
	 *
	 * @param string|null $mode Lock mode: null (wait), 'skip', or 'nowait'
	 * @return object Query instance (chainable)
	 */
	final public function updateLock($mode = null)
	{
		return $this->Query()->updateLock($mode);
	}

	/**
	 * Add row-level SHARE lock (FOR SHARE)
	 *
	 * Applies shared lock on selected rows within a transaction.
	 * Must be chained before all() or first() execution methods.
	 *
	 * Examples:
	 *   UserModel::where('id', 5)->shareLock()->first();
	 *
	 *   ProductModel::where('category', 'electronics')
	 *               ->shareLock('skip')
	 *               ->all();
	 *
	 * @param string|null $mode Lock mode: null (wait), 'skip', or 'nowait'
	 * @return object Query instance (chainable)
	 */
	final public function shareLock($mode = null)
	{
		return $this->Query()->shareLock($mode);
	}

	// =========================================================================
	// SHOULD HAVE FEATURES - Convenience Methods
	// =========================================================================

	/**
	 * Extract single column values as array
	 *
	 * Returns a flat array of values from a single column.
	 *
	 * Examples:
	 *   Posts::pluck('id');
	 *   Posts::where('status', 'published')->pluck('title');
	 *
	 * @param string $column Column name to extract
	 * @return array Flat array of column values
	 */
	final public static function pluck($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->pluck($column);
	}

	/**
	 * Check if any records exist
	 *
	 * Returns true if at least one record matches the query.
	 *
	 * Examples:
	 *   Posts::exists();
	 *   Posts::where('email', 'test@example.com')->exists();
	 *
	 * @return bool True if records exist, false otherwise
	 */
	final public static function exists()
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->exists();
	}

	/**
	 * Filter by date component (day)
	 *
	 * Matches records where date column equals specific date.
	 *
	 * Examples:
	 *   Posts::whereDate('created_at', '2024-01-15')->all();
	 *
	 * @param string $column Date/datetime column name
	 * @param string $date Date in Y-m-d format
	 * @return object Query instance (chainable)
	 */
	final public static function whereDate($column, $date)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereDate($column, $date);
	}

	/**
	 * Filter by month component
	 *
	 * Matches records where date column is in specific month.
	 *
	 * Examples:
	 *   Posts::whereMonth('created_at', 12)->all();
	 *
	 * @param string $column Date/datetime column name
	 * @param int $month Month number (1-12)
	 * @return object Query instance (chainable)
	 */
	final public static function whereMonth($column, $month)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereMonth($column, $month);
	}

	/**
	 * Filter by year component
	 *
	 * Matches records where date column is in specific year.
	 *
	 * Examples:
	 *   Posts::whereYear('created_at', 2024)->all();
	 *
	 * @param string $column Date/datetime column name
	 * @param int $year Year value
	 * @return object Query instance (chainable)
	 */
	final public static function whereYear($column, $year)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereYear($column, $year);
	}

	/**
	 * Paginate results with metadata
	 *
	 * Returns paginated results with additional pagination metadata.
	 *
	 * Examples:
	 *   Posts::paginate(10, 1);
	 *   Posts::where('status', 'published')->paginate(15, 2);
	 *
	 * @param int $perPage Items per page
	 * @param int $page Current page number (1-based)
	 * @return array Pagination result with metadata
	 */
	final public static function paginate($perPage = 15, $page = 1)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->paginate($perPage, $page);
	}

	/**
	 * Update existing record or create new one
	 *
	 * If WHERE clause matches a record, updates it.
	 * If no match, creates new record.
	 *
	 * Examples:
	 *   Posts::where('email', 'test@example.com')->updateOrCreate(['name' => 'John']);
	 *
	 * @param array $values Values to update/insert
	 * @return MySQLResponse Query execution result
	 */
	final public static function updateOrCreate($values)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->updateOrCreate($values);
	}

	/**
	 * Get first matching record or create new one
	 *
	 * If WHERE clause matches a record, returns it.
	 * If no match, creates new record and returns it.
	 *
	 * Examples:
	 *   Posts::where('email', 'test@example.com')->firstOrCreate(['name' => 'John']);
	 *
	 * @param array $values Values to insert if not found
	 * @return array The found or created record
	 */
	final public static function firstOrCreate($values)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->firstOrCreate($values);
	}

	// =========================================================================
	// NICE TO HAVE FEATURES - Advanced Utilities
	// =========================================================================

	/**
	 * Process large datasets in chunks
	 *
	 * Retrieves and processes records in batches to avoid memory issues.
	 *
	 * Examples:
	 *   Posts::chunk(100, function($records) {
	 *       foreach ($records as $record) {
	 *           // Process each record
	 *       }
	 *   });
	 *
	 * @param int $size Chunk size (records per batch)
	 * @param callable $callback Function to execute for each chunk
	 * @return void
	 */
	final public static function chunk($size, $callback)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->chunk($size, $callback);
	}

	/**
	 * Calculate sum of column values
	 *
	 * Returns the sum of all values in a numeric column.
	 *
	 * Examples:
	 *   Posts::sum('views');
	 *   Posts::where('status', 'completed')->sum('amount');
	 *
	 * @param string $column Column name to sum
	 * @return float Sum of column values 
	 */
	final public static function sum($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->sum($column);
	}

	/**
	 * Calculate average of column values
	 *
	 * Returns the average of all values in a numeric column.
	 *
	 * Examples:
	 *   Posts::avg('rating');
	 *   Posts::where('category', 'electronics')->avg('price');
	 *
	 * @param string $column Column name to average
	 * @return float Average of column values
	 */
	final public static function avg($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->avg($column);
	}

	/**
	 * Find minimum column value
	 *
	 * Returns the smallest value in a column.
	 *
	 * Examples:
	 *   Posts::min('price');
	 *   Posts::where('in_stock', 1)->min('price');
	 *
	 * @param string $column Column name
	 * @return mixed Minimum value
	 */
	final public static function min($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->min($column);
	}

	/**
	 * Find maximum column value
	 *
	 * Returns the largest value in a column.
	 *
	 * Examples:
	 *   Posts::max('views');
	 *   Posts::where('category', 'laptops')->max('price');
	 *
	 * @param string $column Column name
	 * @return mixed Maximum value
	 */
	final public static function max($column)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->max($column);
	}

	/**
	 * Compare two columns in WHERE clause
	 *
	 * Adds WHERE condition comparing two columns.
	 *
	 * Examples:
	 *   Posts::whereColumn('first_name', 'last_name')->all();
	 *   Posts::whereColumn('created_at', '>', 'updated_at')->all();
	 *
	 * @param string $column1 First column name
	 * @param string $operatorOrColumn2 Operator or second column name
	 * @param string|null $column2 Second column name (if operator provided)
	 * @return object Query instance (chainable)
	 */
	final public static function whereColumn($column1, $operatorOrColumn2, $column2 = null)
	{
		$instance = new static;
		$instance->setTable();

		return $instance->Query()->whereColumn($column1, $operatorOrColumn2, $column2);
	}
}
