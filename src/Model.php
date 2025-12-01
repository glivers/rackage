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
	 * @return MySQLResponse Response object with insert ID, affected rows, etc.
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
	 * @return MySQLResponse Response object
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
	 * @return MySQLResponse Response object
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
	 * @return MySQLResponse Response object with affected rows count
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
	 * @return MySQLResponse Response object
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
	 * @return MySQLResponse Response object
	 * @throws DatabaseException If query error occurs
	 */
	final public static function rawQuery($query_string)
	{
		return static::Query()->rawQuery($query_string);
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
		return static::Query()->groupBy(...$columns);
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
		return static::Query()->having(...$arguments);
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
		return static::Query()->innerJoin($table, $condition, $fields);
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
		return static::Query()->orWhere(...$arguments);
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
		return static::Query()->whereIn($column, $values);
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
		return static::Query()->whereNotIn($column, $values);
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
		return static::Query()->whereBetween($column, $min, $max);
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
		return static::Query()->whereLike($column, $pattern);
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
		return static::Query()->whereNotLike($column, $pattern);
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
		return static::Query()->whereNull($column);
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
		return static::Query()->whereNotNull($column);
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
		return static::Query()->increment($column, $amount);
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
		return static::Query()->decrement($column, $amount);
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
		return static::Query()->whereFulltext($columns, $search, $mode);
	}

	/**
	 * Execute raw query with parameter binding
	 *
	 * Executes a raw SQL query with safe parameter binding.
	 *
	 * Examples:
	 *   Posts::rawQueryWithBinding('SELECT * FROM posts WHERE age > ? AND status = ?', 18, 'active');
	 *
	 * @param string $query SQL query with ? placeholders
	 * @param mixed ...$params Values to bind (variadic)
	 * @return MySQLResponse Query execution result
	 */
	final public static function rawQueryWithBinding($query, ...$params)
	{
		return static::Query()->rawQueryWithBinding($query, ...$params);
	}

	/**
	 * Begin database transaction
	 *
	 * Starts a transaction to group multiple queries atomically.
	 *
	 * Example:
	 *   Posts::transaction();
	 *   Posts::save(['title' => 'New Post']);
	 *   Posts::commit(); // or Posts::rollback();
	 *
	 * @return bool True on success
	 */
	final public static function transaction()
	{
		return static::Query()->transaction();
	}

	/**
	 * Commit database transaction
	 *
	 * Saves all changes made during the transaction.
	 *
	 * Example:
	 *   Posts::transaction();
	 *   // ... multiple queries ...
	 *   Posts::commit();
	 *
	 * @return bool True on success
	 */
	final public static function commit()
	{
		return static::Query()->commit();
	}

	/**
	 * Rollback database transaction
	 *
	 * Cancels all changes made during the transaction.
	 *  
	 * Example:
	 *   Posts::transaction();
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
		return static::Query()->rollback();
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
		return static::Query()->pluck($column);
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
		return static::Query()->exists();
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
		return static::Query()->whereDate($column, $date);
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
		return static::Query()->whereMonth($column, $month);
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
		return static::Query()->whereYear($column, $year);
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
		return static::Query()->paginate($perPage, $page);
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
		return static::Query()->updateOrCreate($values);
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
		return static::Query()->firstOrCreate($values);
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
		return static::Query()->chunk($size, $callback);
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
		return static::Query()->sum($column);
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
		return static::Query()->avg($column);
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
		return static::Query()->min($column);
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
		return static::Query()->max($column);
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
		return static::Query()->whereColumn($column1, $operatorOrColumn2, $column2);
	}
}
