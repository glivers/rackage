<?php namespace Rackage;

/**
 * Base Model Class
 *
 * All application models extend this base class.
 * Provides a static-only query builder interface for database operations.
 *
 * Static Design:
 *   All methods are static - no instance creation.
 *   Models cannot be instantiated with 'new Model()'.
 *   The constructor is private to enforce the static pattern.
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
 *         protected static $update_timestamps = true;
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
 *   - rawQuery($sql)            Execute raw SQL
 *
 *   SCHEMA:
 *   - createTable()             Create table from model
 *   - updateTable()             Update table structure
 *
 * Security:
 *   - All queries use PDO prepared statements
 *   - Automatic parameter binding prevents SQL injection
 *   - Never concatenate user input into SQL
 *
 * Timestamps:
 *   Set protected static $update_timestamps = true in your model
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
use Rackage\Database\MySQL\MySQLTable;
use Rackage\ModelException;

class Model
{

	/**
	 * Private constructor to prevent instantiation
	 *
	 * Models use a static-only pattern.
	 * You cannot create instances with 'new Model()'.
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Prevent cloning
	 *
	 * @return void
	 */
	private function __clone(){}

	/**
	 * Database connection instance
	 * @var object
	 */
	protected static $connection;

	/**
	 * Query builder instance
	 * @var object
	 */
	protected static $queryObject;

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
	 * Lazy-loads database connection and query builder.
	 * Called internally by all query methods.
	 *
	 * @return object Query builder instance
	 */
	protected static function Query()
	{
		// Get connection if not set
		if(static::$connection === null)
		{
			static::$connection = Registry::get('database');
		}

		// Get query builder if not set
		if(static::$queryObject === null)
		{
			static::$queryObject = static::$connection->query();
		}

		// Mark connection as made
		if(static::$dbConnectionMade === false)
		{
			static::$dbConnectionMade = true;
		}

		return static::$queryObject;
	}

	/**
	 * Set table name for query
	 *
	 * Internal method called before query execution.
	 *
	 * @return void
	 */
	final private static function setTable()
	{
		static::Query()->setTable(static::$table);
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
	 *        ->where('status', 'active')
	 *        ->all();
	 *
	 * @param array $fields Column names to select (default: all columns)
	 * @return static Returns new static instance for chaining
	 */
	final public static function select($fields = array("*"))
	{
		static::Query()->setFields(static::$table, $fields);

		return new static;
	}

	/**
	 * LEFT JOIN another table
	 *
	 * Join related tables to retrieve associated data.
	 *
	 * Examples:
	 *   // Join users table
	 *   Posts::leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *        ->all();
	 *
	 *   // Multiple joins
	 *   Posts::leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *        ->leftJoin('categories', 'posts.category_id = categories.id', ['categories.name'])
	 *        ->all();
	 *
	 * @param string $table Table name to join
	 * @param string $condition Join condition (e.g., 'posts.user_id = users.id')
	 * @param array $fields Columns to select from joined table
	 * @return static Returns new static instance for chaining
	 */
	final public static function leftJoin($table, $condition, $fields = array("*"))
	{
		static::Query()->leftJoin($table, $condition, $fields);

		return new static;
	}

	/**
	 * Limit number of results with pagination
	 *
	 * Limits query results and supports pagination.
	 *
	 * Examples:
	 *   // Get first 10 results
	 *   Posts::limit(10)->all();
	 *
	 *   // Pagination - 20 per page, page 1
	 *   Posts::limit(20, 1)->all();
	 *
	 *   // Pagination - page 2
	 *   Posts::limit(20, 2)->all();
	 *
	 *   // With other conditions
	 *   Posts::where('status', 'published')
	 *        ->order('created_at', 'desc')
	 *        ->limit(10, $page)
	 *        ->all();
	 *
	 * @param int $limit Maximum number of rows to return
	 * @param int $page Page number for pagination (default: 1)
	 * @return static Returns new static instance for chaining
	 */
	final public static function limit($limit, $page = 1)
	{
		static::Query()->limit($limit, $page);

		return new static;
	}

	/**
	 * Get distinct/unique results
	 *
	 * Returns only unique values, eliminating duplicates.
	 *
	 * Examples:
	 *   // Get unique categories
	 *   Posts::select(['category'])->unique()->all();
	 *
	 *   // Get unique status values
	 *   Orders::select(['status'])->unique()->all();
	 *
	 * @return static Returns new static instance for chaining
	 */
	final public static function unique()
	{
		static::Query()->unique();

		return new static;
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
		static::Query()->order($order, $direction);

		return new static;
	}

	/**
	 * Add WHERE conditions
	 *
	 * Filter results based on conditions.
	 * Accepts variadic arguments for flexible usage.
	 *
	 * Examples:
	 *   // Simple equality
	 *   Posts::where('status', 'published')->all();
	 *
	 *   // With operator
	 *   Products::where('price > ?', 100)->all();
	 *
	 *   // Multiple conditions (AND)
	 *   Posts::where('status', 'published')
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
	 * @return static Returns new static instance for chaining
	 */
	final public static function where()
	{
		static::Query()->where(func_get_args());

		return new static;
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
	 *   If $update_timestamps is true, automatically sets:
	 *   - date_created on insert
	 *   - date_modified on update
	 *
	 * @param array $data Associative array of column => value pairs
	 * @return MySQLResponseObject Response object with insert ID, affected rows, etc.
	 */
	final public static function save($data)
	{
		static::setTable();

		// Execute insert or update
		$result = static::$queryObject->save($data, static::$update_timestamps);

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
	 * @return MySQLResponseObject Response object
	 */
	final public static function saveBulk($data, $fields = null, $ids = null, $key = null)
	{
		static::setTable();

		// Execute bulk insert or update
		$result = static::$queryObject->saveBulk($data, $fields, $ids, $key, static::$update_timestamps);

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
	 * @return MySQLResponseObject Response object
	 * @throws ModelException If 'id' field not found or data is empty
	 */
	final public static function saveById($data)
	{
		try
		{
			// Validate ID exists in data
			if(!isset($data['id']))
			{
				throw new ModelException(get_class(new ModelException) ." : The unique ID field for update records was not found in the input array to method updateById()");
			}

			// Extract ID and remove from data
			$id['id'] = $data['id'];
			$data = array_diff_key($data, $id);

			// Validate there is data to update
			if(empty($data))
			{
				throw new ModelException(get_class(new ModelException) ." : There is no data to update in the query submitted by method updateById() ");
			}

			// Build WHERE clause for ID
			static::Query()->where(array('id = ?', $id));
			static::setTable();

			// Execute update
			$result = static::$queryObject->save($data, static::$update_timestamps);

			// Reset query object for next call
			static::$queryObject = null;

			return $result;
		}
		catch (ModelException $e)
		{
			$e->errorShow();
		}
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
	 * @return MySQLResponseObject Response object with affected rows count
	 */
	final public static function delete()
	{
		static::setTable();

		// Execute delete
		$result = static::$queryObject->delete();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
	 * @return MySQLResponseObject Response object
	 */
	final public static function deleteById($id)
	{
		// Build WHERE clause for ID
		static::Query()->where(array('id = ?', $id));
		static::setTable();

		// Execute delete
		$result = static::$queryObject->delete();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::setTable();

		// Execute query
		$result = static::$queryObject->first();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::setTable();

		// Execute count query
		$result = static::$queryObject->count();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::setTable();

		// Execute query
		$result = static::$queryObject->all();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::Query()->where(array('id = ?', $id));
		static::setTable();

		// Execute query
		$result = static::$queryObject->all();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::Query()->where(array('date_created = ?', $dateCreated));
		static::setTable();

		// Execute query
		$result = static::$queryObject->all();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
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
		static::Query()->where(array('date_modified = ?', $dateModified));
		static::setTable();

		// Execute query
		$result = static::$queryObject->all();

		// Reset query object for next call
		static::$queryObject = null;

		return $result;
	}

	// =========================================================================
	// RAW SQL
	// =========================================================================

	/**
	 * Execute raw SQL query
	 *
	 * Executes custom SQL when query builder is insufficient.
	 * Use for complex queries, full-text search, etc.
	 *
	 * Examples:
	 *   // Complex query
	 *   $results = Posts::rawQuery("
	 *       SELECT * FROM posts
	 *       WHERE MATCH(title, content) AGAINST('search term')
	 *   ");
	 *
	 *   // Custom aggregation
	 *   $stats = Posts::rawQuery("
	 *       SELECT category, COUNT(*) as count, AVG(views) as avg_views
	 *       FROM posts
	 *       GROUP BY category
	 *   ");
	 *
	 * WARNING: Ensure proper escaping to prevent SQL injection!
	 *
	 * @param string $query_string SQL query string
	 * @return MySQLResponseObject Response object
	 * @throws DatabaseException If query error occurs
	 */
	final public static function rawQuery($query_string)
	{
		return static::Query()->rawQuery($query_string);
	}

	// =========================================================================
	// SCHEMA METHODS
	// =========================================================================

	/**
	 * Create table from model definition
	 *
	 * Creates database table based on model schema properties.
	 * Requires model to define table structure.
	 *
	 * Example:
	 *   Posts::createTable();
	 *
	 * @return bool True on success
	 */
	final public static function createTable()
	{
		return (new MySQLTable(static::$table, get_called_class(), Registry::get('database')))->createTable();
	}

	/**
	 * Update table structure
	 *
	 * Updates existing table structure to match model definition.
	 * Used for schema migrations.
	 *
	 * Example:
	 *   Posts::updateTable();
	 *
	 * @return bool True on success
	 */
	final public static function updateTable()
	{
		return (new MySQLTable(static::$table, get_called_class(), Registry::get('database')))->updateTable();
	}
}
