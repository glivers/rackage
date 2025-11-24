<?php namespace Rackage\Database\MySQL;

/**
 * MySQL Query Builder
 *
 * This class handles MySQL-specific query construction and execution.
 * It is the implementation layer that Model.php delegates to for all database operations.
 *
 * Architecture:
 *   - Model.php provides static methods for developer-facing API
 *   - Model.php delegates to this class via Query() singleton
 *   - This class builds SQL query strings from method calls
 *   - Query strings are executed via MySQLConnector
 *   - Results are returned via MySQLResponse
 *
 * Query Building Pattern:
 *   1. Query builder methods (setTable, where, order, etc.) store parameters in properties
 *   2. Build methods (buildSelect, buildInsert, etc.) compose SQL from stored properties
 *   3. Execution methods (all, first, save, delete) build SQL and execute via connector
 *   4. Response object contains results, metadata, and query performance metrics
 *
 * Security:
 *   All values are escaped via quote() method which uses MySQLConnector->escape()
 *   WHERE clause uses sprintf with %s placeholders - values are quoted before sprintf
 *   This prevents SQL injection while maintaining query flexibility
 *
 * Example Flow:
 *   Posts::where('status', 'published')->order('created_at', 'desc')->all()
 *     → Model::where() calls MySQLQuery->where(['status', 'published'])
 *     → Model::order() calls MySQLQuery->order('created_at', 'desc')
 *     → Model::all() calls MySQLQuery->all()
 *       → all() calls buildSelect() to compose SQL
 *       → Executes: SELECT * FROM posts WHERE status='published' ORDER BY created_at desc
 *       → Returns MySQLResponse with results
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Database
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\ArrayHelper\ArrayHelper as ArrayUtility;
use Rackage\Database\MySQL\MySQLResponse;
use Rackage\Database\DatabaseException;

class MySQLQuery
{

	// =========================================================================
	// PROPERTIES
	// =========================================================================

	/**
	 * MySQL connection instance
	 * @var object
	 */
	protected $connector;

	/**
	 * Table name for query
	 * @var string
	 */
	protected $froms;

	/**
	 * Fields to select (multidimensional array: table => [fields])
	 * @var array
	 */
	protected $fields;

	/**
	 * Maximum number of rows to return
	 * @var int
	 */
	protected $limits;

	/**
	 * Row offset for pagination
	 * @var int
	 */
	protected $offset;

	/**
	 * ORDER BY clauses (array of ['column' => 'direction'])
	 * @var array
	 */
	protected $orders = array();

	/**
	 * DISTINCT keyword for unique results
	 * @var string
	 */
	protected $distinct = ' ';

	/**
	 * GROUP BY columns
	 * @var array
	 */
	protected $groups = array();

	/**
	 * HAVING conditions (for grouped queries)
	 * @var array
	 */
	protected $havings = array();

	/**
	 * JOIN clauses (tables and conditions)
	 * @var array
	 */
	protected $joins = array();

	/**
	 * WHERE conditions (stores ['condition' => ..., 'operator' => 'AND'|'OR'])
	 * @var array
	 */
	protected $wheres = array();

	/**
	 * Query response object
	 * @var MySQLResponse
	 */
	protected $responseObject;

	// =========================================================================
	// CONSTRUCTOR
	// =========================================================================

	/**
	 * Constructor - Initialize query builder
	 *
	 * Sets up the MySQL connection and response object.
	 *
	 * @param array $instance Array containing 'connector' key with MySQLConnector instance
	 * @return void
	 */
	public function __construct(array $instance)
	{
		// Store connection instance
		$this->connector = $instance['connector'];

		// Create response object instance
		$this->responseObject = new MySQLResponse();
	}

	// =========================================================================
	// SECURITY / ESCAPING
	// =========================================================================

	/**
	 * Quote and escape values for MySQL
	 *
	 * Escapes and quotes input data according to MySQL requirements.
	 * Handles strings, integers, arrays, nulls, booleans, and empty values.
	 *
	 * Security:
	 *   Uses MySQLConnector->escape() for proper escaping.
	 *   Prevents SQL injection by sanitizing all values.
	 *
	 * Examples:
	 *   quote('hello')        → 'hello'
	 *   quote(123)            → '123'
	 *   quote(['a', 'b'])     → ('a', 'b')
	 *   quote(null)           → NULL
	 *   quote(true)           → 1
	 *
	 * @param mixed $value Value to quote (string, int, array, null, bool)
	 * @return mixed Quoted and escaped value
	 */
	protected function quote($value)
	{
		// String or integer - escape and quote
		if (is_string($value) || is_int($value))
		{
			$escaped = $this->connector->escape($value);

			return "'{$escaped}'";
		}

		// Array - quote each element and join
		elseif (is_array($value))
		{
			$buffer = array();

			foreach ($value as $i)
			{
				// Recursively quote each element
				array_push($buffer, $this->quote($i));

				// Join array elements
				$buffer = join(", ", $buffer);

				// Return in parentheses
				return "({$buffer})";
			}
		}

		// NULL value
		elseif (is_null($value))
		{
			return 'NULL';
		}

		// Boolean - convert to integer
		elseif (is_bool($value))
		{
			return (int)$value;
		}

		// Empty value
		elseif (empty($value))
		{
			return "' '";
		}

		// Fallback - escape and return
		else
		{
			return $this->connector->escape($value);
		}
	}

	// =========================================================================
	// QUERY BUILDER METHODS (Set Parameters)
	// =========================================================================

	/**
	 * Set table name for query
	 *
	 * Specifies which table to query.
	 * Automatically initializes fields array with '*' (all columns).
	 *
	 * @param string $table Table name
	 * @return $this For method chaining
	 * @throws DatabaseException If table name is empty
	 */
	public function setTable($table)
	{
		try
		{
			// Validate table name is not empty
			if (empty($table))
			{
				throw new DatabaseException("Invalid argument passed for table name", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Set table name
		$this->froms = $table;

		// Initialize fields if not set
		if(!isset($this->fields[$table]))
		{
			$this->fields[$table] = array("*");
		}

		return $this;
	}

	/**
	 * Set columns to select from table
	 *
	 * Specifies which columns to retrieve from the table.
	 *
	 * @param string $table Table name
	 * @param array $fields Column names to select (default: all columns)
	 * @return void
	 * @throws DatabaseException If table name is empty
	 */
	public function setFields($table, $fields = array("*"))
	{
		try
		{
			// Validate table name is not empty
			if (empty($table))
			{
				throw new DatabaseException("Invalid argument passed for table name", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Set table and fields
		$this->froms = $table;
		$this->fields[$table] = $fields;
	}

	/**
	 * Add LEFT JOIN to query
	 *
	 * Joins another table to retrieve related data.
	 * Supports multiple joins by calling this method multiple times.
	 *
	 * Examples:
	 *   leftJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *   leftJoin('categories', 'posts.category_id = categories.id', ['*'])
	 *
	 * @param string $table Table name to join
	 * @param string $condition Join condition (e.g., 'posts.user_id = users.id')
	 * @param array $fields Columns to select from joined table
	 * @return $this For method chaining
	 * @throws DatabaseException If table or condition is empty
	 */
	public function leftJoin($table, $condition, $fields = array("*"))
	{
		try
		{
			// Validate table is not empty
			if (empty($table))
			{
				throw new DatabaseException("Invalid table argument $table passed for the leftJoin Clause", 1);
			}

			// Validate condition is not empty
			if (empty($condition))
			{
				throw new DatabaseException("Invalid argument $condition passed for the leftJoin Clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Add fields for this table
		$this->fields += array($table => $fields);

		// Store join table, condition, and type
		$this->joins['tables'][] = $table;
		$this->joins['conditions'][] = $condition;
		$this->joins['types'][] = 'LEFT';  // Mark as LEFT JOIN

		return $this;
	}

	/**
	 * Set row limit with pagination
	 *
	 * Limits the number of rows returned by the query.
	 * Supports pagination by calculating offset from page number.
	 *
	 * Examples:
	 *   limit(10)      → LIMIT 10
	 *   limit(20, 1)   → LIMIT 0, 20 (page 1)
	 *   limit(20, 2)   → LIMIT 20, 20 (page 2)
	 *   limit(20, 3)   → LIMIT 40, 20 (page 3)
	 *
	 * @param int $limit Maximum number of rows to return
	 * @param int $page Page number for pagination (default: 1)
	 * @return $this For method chaining
	 * @throws DatabaseException If limit is empty
	 */
	public function limit($limit, $page = 1)
	{
		try
		{
			// Validate limit is not empty
			if (empty($limit))
			{
				throw new DatabaseException("Empty argument passed for $limit in method limit()", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Set limit
		$this->limits = $limit;

		// Calculate offset for pagination
		$this->offset = (int)$limit * ($page - 1);

		return $this;
	}

	/**
	 * Set DISTINCT to return only unique results
	 *
	 * Adds DISTINCT keyword to query to eliminate duplicate rows.
	 *
	 * @return $this For method chaining
	 */
	public function unique()
	{
		$this->distinct = ' DISTINCT ';

		return $this;
	}

	/**
	 * Add ORDER BY clause
	 *
	 * Specifies how to sort query results.
	 * Supports multiple order clauses - call multiple times for multi-column sorting.
	 *
	 * Examples:
	 *   order('created_at', 'desc')  → ORDER BY created_at desc
	 *   order('name', 'asc')         → ORDER BY name asc
	 *
	 *   // Multiple columns
	 *   order('featured', 'desc')->order('created_at', 'desc')
	 *   → ORDER BY featured desc, created_at desc
	 *
	 * @param string $order Column name to sort by
	 * @param string $direction Sort direction: 'asc' or 'desc' (default: 'asc')
	 * @return $this For method chaining
	 * @throws DatabaseException If order is empty
	 */
	public function order($order, $direction = 'asc')
	{
		try
		{
			// Validate order field is not empty
			if (empty($order))
			{
				throw new DatabaseException("Empty value passed for parameter $order in order() method", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Add order clause to array (supports multiple ORDER BY)
		$this->orders[$order] = $direction;

		return $this;
	}

	/**
	 * Add WHERE conditions
	 *
	 * Builds WHERE clause from arguments.
	 * Supports multiple WHERE calls - they are combined with AND.
	 *
	 * Usage Patterns:
	 *   where(['status', 'published'])
	 *     → WHERE status='published'
	 *
	 *   where(['price > ?', 100])
	 *     → WHERE price > 100
	 *
	 *   where(['status = ? AND views > ?', 'published', 1000])
	 *     → WHERE status='published' AND views > 1000
	 *
	 * Security:
	 *   Values are quoted via quote() method before insertion.
	 *   Uses sprintf with %s placeholders for safe substitution.
	 *
	 * @param array $arguments Arguments array from Model::where()
	 * @return $this For method chaining
	 * @throws DatabaseException If argument count is invalid
	 */
	public function where($arguments)
	{
		try
		{
			// Validate argument pairs match
			if (is_float(sizeof($arguments) / 2))
			{
				throw new DatabaseException("No arguments passed for the where clause");
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Single argument pair (field, value)
		if (sizeof($arguments) == 2)
		{
			// Replace ? with %s placeholder
			$arguments[0] = preg_replace("#\?#", "%s", $arguments[0]);

			// Quote the value
			$arguments[1] = $this->quote($arguments[1]);

			// Build WHERE clause with sprintf and store with AND operator
			$this->wheres[] = array(
				'condition' => call_user_func_array("sprintf", $arguments),
				'operator' => 'AND'
			);

			return $this;
		}

		// Multiple argument pairs
		else
		{
			// Calculate number of iterations
			$count = sizeof($arguments) / 2;

			// Process each pair
			for ($i = 0; $i < $count; $i++)
			{
				// Extract one pair
				$argumentsPair = array_splice($arguments, 0, 2);

				// Replace ? with %s placeholder
				$argumentsPair[0] = preg_replace("#\?#", "%s", $argumentsPair[0]);

				// Quote the value
				$argumentsPair[1] = $this->quote($argumentsPair[1]);

				// Build WHERE clause with sprintf and store with AND operator
				$this->wheres[] = array(
					'condition' => call_user_func_array("sprintf", $argumentsPair),
					'operator' => 'AND'
				);
			}

			return $this;
		}
	}

	/**
	 * Add OR WHERE clause
	 *
	 * Adds WHERE conditions with OR operator instead of AND.
	 * Use when you want records matching ANY of the conditions.
	 *
	 * Syntax: Same as where() but uses OR operator.
	 *
	 * Examples:
	 *   orWhere('status', 'draft')
	 *   → WHERE ... OR status = 'draft'
	 *
	 *   orWhere('views > ?', 1000)
	 *   → WHERE ... OR views > 1000
	 *
	 *   orWhere('author_id = ?', 5, 'status = ?', 'published')
	 *   → WHERE ... OR author_id = 5 OR status = 'published'
	 *
	 * @param mixed ...$arguments Variadic arguments (condition, value pairs)
	 * @return MySQLQuery Chainable query instance
	 */
	public function orWhere($arguments)
	{
		try
		{
			// Validate argument pairs match
			if (is_float(sizeof($arguments) / 2))
			{
				throw new DatabaseException("Invalid arguments passed for the orWhere clause");
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Single argument pair (field, value)
		if (sizeof($arguments) == 2)
		{
			// Replace ? with %s placeholder
			$arguments[0] = preg_replace("#\?#", "%s", $arguments[0]);

			// Quote the value
			$arguments[1] = $this->quote($arguments[1]);

			// Build WHERE clause with sprintf and store with OR operator
			$this->wheres[] = array(
				'condition' => call_user_func_array("sprintf", $arguments),
				'operator' => 'OR'
			);

			return $this;
		}

		// Multiple argument pairs
		else
		{
			// Calculate number of iterations
			$count = sizeof($arguments) / 2;

			// Process each pair
			for ($i = 0; $i < $count; $i++)
			{
				// Extract one pair
				$argumentsPair = array_splice($arguments, 0, 2);

				// Replace ? with %s placeholder
				$argumentsPair[0] = preg_replace("#\?#", "%s", $argumentsPair[0]);

				// Quote the value
				$argumentsPair[1] = $this->quote($argumentsPair[1]);

				// Build WHERE clause with sprintf and store with OR operator
				$this->wheres[] = array(
					'condition' => call_user_func_array("sprintf", $argumentsPair),
					'operator' => 'OR'
				);
			}

			return $this;
		}
	}

	/**
	 * Add WHERE IN clause
	 *
	 * Checks if column value matches any value in an array.
	 * More efficient than multiple OR conditions.
	 *
	 * Examples:
	 *   whereIn('id', [1, 2, 3, 4, 5])
	 *   → WHERE id IN (1, 2, 3, 4, 5)
	 *
	 *   whereIn('status', ['draft', 'pending', 'review'])
	 *   → WHERE status IN ('draft', 'pending', 'review')
	 *
	 * @param string $column Column name
	 * @param array $values Array of values to match
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereIn($column, $values)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereIn clause", 1);
			}

			if (!is_array($values) || empty($values))
			{
				throw new DatabaseException("Invalid or empty array provided for whereIn clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Quote the array values (quote() handles arrays)
		$quotedValues = $this->quote($values);

		// Build WHERE IN condition
		$this->wheres[] = array(
			'condition' => "{$column} IN {$quotedValues}",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE NOT IN clause
	 *
	 * Checks if column value does NOT match any value in an array.
	 * Opposite of whereIn().
	 *
	 * Examples:
	 *   whereNotIn('status', ['deleted', 'banned'])
	 *   → WHERE status NOT IN ('deleted', 'banned')
	 *
	 *   whereNotIn('id', [10, 20, 30])
	 *   → WHERE id NOT IN (10, 20, 30)
	 *
	 * @param string $column Column name
	 * @param array $values Array of values to exclude
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereNotIn($column, $values)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereNotIn clause", 1);
			}

			if (!is_array($values) || empty($values))
			{
				throw new DatabaseException("Invalid or empty array provided for whereNotIn clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Quote the array values (quote() handles arrays)
		$quotedValues = $this->quote($values);

		// Build WHERE NOT IN condition
		$this->wheres[] = array(
			'condition' => "{$column} NOT IN {$quotedValues}",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE BETWEEN clause
	 *
	 * Checks if column value falls within a range (inclusive).
	 * Works with numbers, dates, and strings.
	 *
	 * Examples:
	 *   whereBetween('age', 18, 65)
	 *   → WHERE age BETWEEN 18 AND 65
	 *
	 *   whereBetween('created_at', '2024-01-01', '2024-12-31')
	 *   → WHERE created_at BETWEEN '2024-01-01' AND '2024-12-31'
	 *
	 *   whereBetween('price', 10.00, 99.99)
	 *   → WHERE price BETWEEN 10.00 AND 99.99
	 *
	 * @param string $column Column name
	 * @param mixed $min Minimum value (inclusive)
	 * @param mixed $max Maximum value (inclusive)
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereBetween($column, $min, $max)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereBetween clause", 1);
			}

			if ($min === null || $max === null)
			{
				throw new DatabaseException("Invalid min/max values provided for whereBetween clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Quote min and max values
		$quotedMin = $this->quote($min);
		$quotedMax = $this->quote($max);

		// Build WHERE BETWEEN condition
		$this->wheres[] = array(
			'condition' => "{$column} BETWEEN {$quotedMin} AND {$quotedMax}",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE LIKE clause
	 *
	 * Performs pattern matching on column values.
	 * Supports wildcards: % (any characters), _ (single character).
	 *
	 * Examples:
	 *   whereLike('title', 'WordPress%')
	 *   → WHERE title LIKE 'WordPress%'
	 *
	 *   whereLike('email', '%@gmail.com')
	 *   → WHERE email LIKE '%@gmail.com'
	 *
	 *   whereLike('name', '%John%')
	 *   → WHERE name LIKE '%John%'
	 *
	 * @param string $column Column name
	 * @param string $pattern Pattern to match (with % or _ wildcards)
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereLike($column, $pattern)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereLike clause", 1);
			}

			if ($pattern === null || $pattern === '')
			{
				throw new DatabaseException("No pattern provided for whereLike clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Quote the pattern
		$quotedPattern = $this->quote($pattern);

		// Build WHERE LIKE condition
		$this->wheres[] = array(
			'condition' => "{$column} LIKE {$quotedPattern}",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE NOT LIKE clause
	 *
	 * Performs pattern exclusion on column values.
	 * Opposite of whereLike().
	 *
	 * Examples:
	 *   whereNotLike('email', '%spam.com')
	 *   → WHERE email NOT LIKE '%spam.com'
	 *
	 *   whereNotLike('title', 'Draft:%')
	 *   → WHERE title NOT LIKE 'Draft:%'
	 *
	 * @param string $column Column name
	 * @param string $pattern Pattern to exclude (with % or _ wildcards)
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereNotLike($column, $pattern)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereNotLike clause", 1);
			}

			if ($pattern === null || $pattern === '')
			{
				throw new DatabaseException("No pattern provided for whereNotLike clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Quote the pattern
		$quotedPattern = $this->quote($pattern);

		// Build WHERE NOT LIKE condition
		$this->wheres[] = array(
			'condition' => "{$column} NOT LIKE {$quotedPattern}",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE NULL clause
	 *
	 * Checks if column value is NULL.
	 *
	 * Examples:
	 *   whereNull('deleted_at')
	 *   → WHERE deleted_at IS NULL
	 *
	 *   whereNull('parent_id')
	 *   → WHERE parent_id IS NULL
	 *
	 * @param string $column Column name
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereNull($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereNull clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Build WHERE IS NULL condition
		$this->wheres[] = array(
			'condition' => "{$column} IS NULL",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Add WHERE NOT NULL clause
	 *
	 * Checks if column value is NOT NULL.
	 *
	 * Examples:
	 *   whereNotNull('published_at')
	 *   → WHERE published_at IS NOT NULL
	 *
	 *   whereNotNull('user_id')
	 *   → WHERE user_id IS NOT NULL
	 *
	 * @param string $column Column name
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereNotNull($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereNotNull clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Build WHERE IS NOT NULL condition
		$this->wheres[] = array(
			'condition' => "{$column} IS NOT NULL",
			'operator' => 'AND'
		);

		return $this;
	}

	/**
	 * Increment a column value
	 *
	 * Increases a numeric column by specified amount (default: 1).
	 * Useful for counters, views, votes, etc.
	 *
	 * Examples:
	 *   increment('views')
	 *   → UPDATE table SET views = views + 1
	 *
	 *   increment('votes', 5)
	 *   → UPDATE table SET votes = votes + 5
	 *
	 * @param string $column Column name to increment
	 * @param int $amount Amount to increment by (default: 1)
	 * @return MySQLResponse Query execution result
	 */
	public function increment($column, $amount = 1)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for increment operation", 1);
			}

			if (!is_numeric($amount) || $amount <= 0)
			{
				throw new DatabaseException("Invalid increment amount (must be positive number)", 1);
			}

			// Build INCREMENT query manually
			$where = '';

			// Build WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$where = "WHERE " . join(" ", $whereParts);
			}

			// Build query
			$query = "UPDATE {$this->froms} SET {$column} = {$column} + {$amount} {$where}";

			// Execute
			$result = $this->connector->execute($query);

			// Build response
			if ($result === false)
			{
				throw new DatabaseException("Increment query failed: " . $this->connector->lastError(), 1);
			}

			// Set response data
			$this->responseObject->setQueryString($query);
			$this->responseObject->setAffectedRows($this->connector->affectedRows());
			$this->responseObject->setUpdateSuccess(true);

			return $this->responseObject;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Decrement a column value
	 *
	 * Decreases a numeric column by specified amount (default: 1).
	 * Useful for stock counters, credits, etc.
	 *
	 * Examples:
	 *   decrement('stock')
	 *   → UPDATE table SET stock = stock - 1
	 *
	 *   decrement('credits', 10)
	 *   → UPDATE table SET credits = credits - 10
	 *
	 * @param string $column Column name to decrement
	 * @param int $amount Amount to decrement by (default: 1)
	 * @return MySQLResponse Query execution result
	 */
	public function decrement($column, $amount = 1)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for decrement operation", 1);
			}

			if (!is_numeric($amount) || $amount <= 0)
			{
				throw new DatabaseException("Invalid decrement amount (must be positive number)", 1);
			}

			// Build DECREMENT query manually
			$where = '';

			// Build WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$where = "WHERE " . join(" ", $whereParts);
			}

			// Build query
			$query = "UPDATE {$this->froms} SET {$column} = {$column} - {$amount} {$where}";

			// Execute
			$result = $this->connector->execute($query);

			// Build response
			if ($result === false)
			{
				throw new DatabaseException("Decrement query failed: " . $this->connector->lastError(), 1);
			}

			// Set response data
			$this->responseObject->setQueryString($query);
			$this->responseObject->setAffectedRows($this->connector->affectedRows());
			$this->responseObject->setUpdateSuccess(true);

			return $this->responseObject;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Add FULLTEXT search clause
	 *
	 * Performs full-text search on columns with FULLTEXT index.
	 * Much faster than LIKE for searching large text content.
	 * Requires columns to have FULLTEXT index defined.
	 *
	 * Search Modes:
	 *   - 'natural' (default): Natural language search
	 *   - 'boolean': Boolean mode with +/- operators
	 *   - 'expansion': Natural language with query expansion
	 *
	 * Examples:
	 *   whereFulltext(['title', 'content'], 'WordPress tutorial')
	 *   → WHERE MATCH(title, content) AGAINST('WordPress tutorial' IN NATURAL LANGUAGE MODE)
	 *
	 *   whereFulltext(['body'], '+MySQL -Oracle', 'boolean')
	 *   → WHERE MATCH(body) AGAINST('+MySQL -Oracle' IN BOOLEAN MODE)
	 *
	 * @param array $columns Array of column names with FULLTEXT index
	 * @param string $search Search query string
	 * @param string $mode Search mode: 'natural', 'boolean', 'expansion'
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereFulltext($columns, $search, $mode = 'natural')
	{
		try
		{
			if (!is_array($columns) || empty($columns))
			{
				throw new DatabaseException("Invalid columns array for whereFulltext clause", 1);
			}

			if (empty($search))
			{
				throw new DatabaseException("No search term provided for whereFulltext clause", 1);
			}

			$validModes = ['natural', 'boolean', 'expansion'];

			if (!in_array($mode, $validModes))
			{
				throw new DatabaseException("Invalid search mode '{$mode}' for whereFulltext clause", 1);
			}

			// Map mode to MySQL syntax
			$modeMap = [
				'natural' => 'IN NATURAL LANGUAGE MODE',
				'boolean' => 'IN BOOLEAN MODE',
				'expansion' => 'IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION'
			];

			$modeString = $modeMap[$mode];

			// Quote search term
			$quotedSearch = $this->quote($search);

			// Build MATCH...AGAINST clause
			$columnList = join(', ', $columns);

			$this->wheres[] = array(
				'condition' => "MATCH({$columnList}) AGAINST({$quotedSearch} {$modeString})",
				'operator' => 'AND'
			);

			return $this;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Execute raw query with parameter binding
	 *
	 * Executes a raw SQL query with safe parameter binding.
	 * Use this when query builder doesn't support your query.
	 *
	 * Parameter Binding:
	 *   Use ? as placeholders, pass values as additional arguments.
	 *   Values are automatically escaped for security.
	 *
	 * Examples:
	 *   rawQueryWithBinding('SELECT * FROM users WHERE age > ? AND status = ?', 18, 'active')
	 *   → SELECT * FROM users WHERE age > 18 AND status = 'active'
	 *
	 *   rawQueryWithBinding('UPDATE posts SET views = views + ? WHERE id = ?', 1, 123)
	 *   → UPDATE posts SET views = views + 1 WHERE id = 123
	 *
	 * @param string $query SQL query with ? placeholders
	 * @param mixed ...$params Values to bind (variadic)
	 * @return MySQLResponse Query execution result
	 */
	public function rawQueryWithBinding($query, ...$params)
	{
		try
		{
			if (empty($query))
			{
				throw new DatabaseException("No query provided for rawQueryWithBinding", 1);
			}

			// Count placeholders
			$placeholderCount = substr_count($query, '?');

			if ($placeholderCount !== count($params))
			{
				throw new DatabaseException("Parameter count mismatch: {$placeholderCount} placeholders, " . count($params) . " params provided", 1);
			}

			// Replace ? with %s for sprintf
			$query = preg_replace("#\?#", "%s", $query);

			// Quote all parameters
			$quotedParams = array_map(function($param) {
				return $this->quote($param);
			}, $params);

			// Build final query
			$finalQuery = vsprintf($query, $quotedParams);

			// Execute using existing rawQuery method
			return $this->rawQuery($finalQuery);
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Begin database transaction
	 *
	 * Starts a transaction to group multiple queries atomically.
	 * Use commit() to save changes or rollback() to cancel.
	 *
	 * Transaction Benefits:
	 *   - Atomicity: All queries succeed or all fail
	 *   - Prevents race conditions
	 *   - Maintains data consistency
	 *
	 * Example:
	 *   $db->transaction();
	 *   $db->save(['user_id' => 1, 'amount' => 100]);
	 *   $db->where('id', 1)->save(['balance' => 'balance - 100']);
	 *   $db->commit(); // or $db->rollback();
	 *
	 * @return bool True on success
	 */
	public function transaction()
	{
		try
		{
			$result = $this->connector->execute("START TRANSACTION");

			if ($result === false)
			{
				throw new DatabaseException("Failed to start transaction: " . $this->connector->lastError(), 1);
			}

			return true;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Commit database transaction
	 *
	 * Saves all changes made during the transaction.
	 * Call after successful completion of all operations.
	 *
	 * Example:
	 *   $db->transaction();
	 *   // ... multiple queries ...
	 *   $db->commit(); // Save all changes
	 *
	 * @return bool True on success
	 */
	public function commit()
	{
		try
		{
			$result = $this->connector->execute("COMMIT");

			if ($result === false)
			{
				throw new DatabaseException("Failed to commit transaction: " . $this->connector->lastError(), 1);
			}

			return true;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Rollback database transaction
	 *
	 * Cancels all changes made during the transaction.
	 * Call when an error occurs or operation needs to be cancelled.
	 *
	 * Example:
	 *   $db->transaction();
	 *   try {
	 *       // ... queries ...
	 *       $db->commit();
	 *   } catch (Exception $e) {
	 *       $db->rollback(); // Cancel all changes
	 *   }
	 *
	 * @return bool True on success
	 */
	public function rollback()
	{
		try
		{
			$result = $this->connector->execute("ROLLBACK");

			if ($result === false)
			{
				throw new DatabaseException("Failed to rollback transaction: " . $this->connector->lastError(), 1);
			}

			return true;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	// =========================================================================
	// SHOULD HAVE FEATURES - Convenience Methods
	// =========================================================================

	/**
	 * Extract single column values as array
	 *
	 * Returns a flat array of values from a single column.
	 * Useful for getting lists of IDs, names, etc.
	 *
	 * Examples:
	 *   pluck('id')
	 *   → [1, 2, 3, 4, 5]
	 *
	 *   pluck('email')
	 *   → ['user1@example.com', 'user2@example.com', ...]
	 *
	 * @param string $column Column name to extract
	 * @return array Flat array of column values
	 */
	public function pluck($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for pluck operation", 1);
			}

			// Get all results
			$results = $this->all();

			// Extract column values
			$values = array();

			foreach ($results as $row)
			{
				if (isset($row[$column]))
				{
					$values[] = $row[$column];
				}
			}

			return $values;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Check if any records exist
	 *
	 * Returns true if at least one record matches the query.
	 * More efficient than count() > 0.
	 *
	 * Examples:
	 *   exists()
	 *   → true/false
	 *
	 *   where('email', 'test@example.com')->exists()
	 *   → true/false
	 *
	 * @return bool True if records exist, false otherwise
	 */
	public function exists()
	{
		try
		{
			// Use LIMIT 1 for efficiency
			$this->limits = 1;
			$this->offset = 0;

			// Build and execute query
			$query = $this->buildSelect();
			$result = $this->connector->execute($query);

			if ($result === false)
			{
				throw new DatabaseException("Exists query failed: " . $this->connector->lastError(), 1);
			}

			// Check if any rows returned
			return $result->num_rows > 0;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Filter by date component (day)
	 *
	 * Matches records where date column equals specific date (ignoring time).
	 *
	 * Examples:
	 *   whereDate('created_at', '2024-01-15')
	 *   → WHERE DATE(created_at) = '2024-01-15'
	 *
	 * @param string $column Date/datetime column name
	 * @param string $date Date in Y-m-d format
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereDate($column, $date)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereDate clause", 1);
			}

			if (empty($date))
			{
				throw new DatabaseException("No date provided for whereDate clause", 1);
			}

			// Quote the date
			$quotedDate = $this->quote($date);

			// Build WHERE DATE() condition
			$this->wheres[] = array(
				'condition' => "DATE({$column}) = {$quotedDate}",
				'operator' => 'AND'
			);

			return $this;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Filter by month component
	 *
	 * Matches records where date column is in specific month (1-12).
	 *
	 * Examples:
	 *   whereMonth('created_at', 12)
	 *   → WHERE MONTH(created_at) = 12
	 *
	 * @param string $column Date/datetime column name
	 * @param int $month Month number (1-12)
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereMonth($column, $month)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereMonth clause", 1);
			}

			if ($month < 1 || $month > 12)
			{
				throw new DatabaseException("Invalid month value (must be 1-12)", 1);
			}

			// Build WHERE MONTH() condition
			$this->wheres[] = array(
				'condition' => "MONTH({$column}) = {$month}",
				'operator' => 'AND'
			);

			return $this;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Filter by year component
	 *
	 * Matches records where date column is in specific year.
	 *
	 * Examples:
	 *   whereYear('created_at', 2024)
	 *   → WHERE YEAR(created_at) = 2024
	 *
	 * @param string $column Date/datetime column name
	 * @param int $year Year value
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereYear($column, $year)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for whereYear clause", 1);
			}

			if (!is_numeric($year) || $year < 1000 || $year > 9999)
			{
				throw new DatabaseException("Invalid year value", 1);
			}

			// Build WHERE YEAR() condition
			$this->wheres[] = array(
				'condition' => "YEAR({$column}) = {$year}",
				'operator' => 'AND'
			);

			return $this;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Paginate results with metadata
	 *
	 * Returns paginated results with additional pagination metadata.
	 * More convenient than manually calculating LIMIT/OFFSET.
	 *
	 * Returns array with:
	 *   - 'data': Result rows
	 *   - 'current_page': Current page number
	 *   - 'per_page': Items per page
	 *   - 'total': Total matching records
	 *   - 'last_page': Last page number
	 *   - 'from': First item number on page
	 *   - 'to': Last item number on page
	 *
	 * Examples:
	 *   paginate(10, 1)
	 *   → ['data' => [...], 'current_page' => 1, 'per_page' => 10, ...]
	 *
	 * @param int $perPage Items per page
	 * @param int $page Current page number (1-based)
	 * @return array Pagination result with metadata
	 */
	public function paginate($perPage = 15, $page = 1)
	{
		try
		{
			if ($perPage < 1)
			{
				throw new DatabaseException("Invalid perPage value (must be >= 1)", 1);
			}

			if ($page < 1)
			{
				throw new DatabaseException("Invalid page value (must be >= 1)", 1);
			}

			// Get total count (before applying limit)
			$countQuery = clone $this;
			$total = $countQuery->count();

			// Apply pagination
			$this->limit($perPage, $page);

			// Get results
			$data = $this->all();

			// Calculate metadata
			$lastPage = (int)ceil($total / $perPage);
			$from = (($page - 1) * $perPage) + 1;
			$to = min($from + $perPage - 1, $total);

			return array(
				'data' => $data,
				'current_page' => $page,
				'per_page' => $perPage,
				'total' => $total,
				'last_page' => $lastPage,
				'from' => $total > 0 ? $from : null,
				'to' => $total > 0 ? $to : null
			);
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Update existing record or create new one
	 *
	 * If WHERE clause matches a record, updates it.
	 * If no match, creates new record with attributes + values.
	 *
	 * Examples:
	 *   where('email', 'test@example.com')->updateOrCreate(['name' => 'John'])
	 *   → Updates if exists, creates if not
	 *
	 * @param array $values Values to update/insert
	 * @return MySQLResponse Query execution result
	 */
	public function updateOrCreate($values)
	{
		try
		{
			if (empty($values))
			{
				throw new DatabaseException("No values provided for updateOrCreate", 1);
			}

			// Check if record exists
			if ($this->exists())
			{
				// Update existing record
				return $this->save($values);
			}
			else
			{
				// Create new record
				// Need to reset WHERE clauses for insert
				$insertQuery = new static(['connector' => $this->connector]);
				$insertQuery->froms = $this->froms;

				return $insertQuery->save($values);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Get first matching record or create new one
	 *
	 * If WHERE clause matches a record, returns it.
	 * If no match, creates new record with attributes and returns it.
	 *
	 * Examples:
	 *   where('email', 'test@example.com')->firstOrCreate(['name' => 'John'])
	 *   → Returns existing or creates new
	 *
	 * @param array $values Values to insert if not found
	 * @return array The found or created record
	 */
	public function firstOrCreate($values)
	{
		try
		{
			if (empty($values))
			{
				throw new DatabaseException("No values provided for firstOrCreate", 1);
			}

			// Try to get existing record
			$existing = $this->first();

			if ($existing)
			{
				// Return existing record
				return $existing;
			}
			else
			{
				// Create new record
				$insertQuery = new static(['connector' => $this->connector]);
				$insertQuery->froms = $this->froms;

				$result = $insertQuery->save($values);

				// Get the newly created record
				$lastId = $result->lastInsertId();

				$newQuery = new static(['connector' => $this->connector]);
				$newQuery->froms = $this->froms;

				return $newQuery->where('id', $lastId)->first();
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	// =========================================================================
	// NICE TO HAVE FEATURES - Advanced Utilities
	// =========================================================================

	/**
	 * Process large datasets in chunks
	 *
	 * Retrieves and processes records in batches to avoid memory issues.
	 * Executes callback function for each chunk.
	 *
	 * Examples:
	 *   chunk(100, function($records) {
	 *       foreach ($records as $record) {
	 *           // Process each record
	 *       }
	 *   });
	 *
	 * @param int $size Chunk size (records per batch)
	 * @param callable $callback Function to execute for each chunk
	 * @return void
	 */
	public function chunk($size, $callback)
	{
		try
		{
			if ($size < 1)
			{
				throw new DatabaseException("Invalid chunk size (must be >= 1)", 1);
			}

			if (!is_callable($callback))
			{
				throw new DatabaseException("Invalid callback function for chunk operation", 1);
			}

			$page = 1;

			do
			{
				// Get chunk
				$chunkQuery = clone $this;
				$chunkQuery->limit($size, $page);
				$results = $chunkQuery->all();

				// Stop if no results
				if (empty($results))
				{
					break;
				}

				// Execute callback
				$continue = call_user_func($callback, $results);

				// Allow callback to stop iteration by returning false
				if ($continue === false)
				{
					break;
				}

				$page++;
			}
			while (count($results) === $size);
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Calculate sum of column values
	 *
	 * Returns the sum of all values in a numeric column.
	 *
	 * Examples:
	 *   sum('price')
	 *   → 1500.00
	 *
	 *   where('status', 'completed')->sum('amount')
	 *   → 5000.00
	 *
	 * @param string $column Column name to sum
	 * @return float Sum of column values
	 */
	public function sum($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for sum operation", 1);
			}

			// Build query with SUM function
			$query = "SELECT SUM({$column}) as total FROM {$this->froms}";

			// Add WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$query .= " WHERE " . join(" ", $whereParts);
			}

			// Execute query
			$result = $this->connector->execute($query);

			if ($result === false)
			{
				throw new DatabaseException("Sum query failed: " . $this->connector->lastError(), 1);
			}

			// Get result
			$row = $result->fetch_assoc();

			return $row['total'] !== null ? (float)$row['total'] : 0.0;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Calculate average of column values
	 *
	 * Returns the average of all values in a numeric column.
	 *
	 * Examples:
	 *   avg('rating')
	 *   → 4.5
	 *
	 *   where('category', 'electronics')->avg('price')
	 *   → 299.99
	 *
	 * @param string $column Column name to average
	 * @return float Average of column values
	 */
	public function avg($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for avg operation", 1);
			}

			// Build query with AVG function
			$query = "SELECT AVG({$column}) as average FROM {$this->froms}";

			// Add WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$query .= " WHERE " . join(" ", $whereParts);
			}

			// Execute query
			$result = $this->connector->execute($query);

			if ($result === false)
			{
				throw new DatabaseException("Average query failed: " . $this->connector->lastError(), 1);
			}

			// Get result
			$row = $result->fetch_assoc();

			return $row['average'] !== null ? (float)$row['average'] : 0.0;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Find minimum column value
	 *
	 * Returns the smallest value in a column.
	 *
	 * Examples:
	 *   min('price')
	 *   → 9.99
	 *
	 *   where('in_stock', 1)->min('price')
	 *   → 19.99
	 *
	 * @param string $column Column name
	 * @return mixed Minimum value
	 */
	public function min($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for min operation", 1);
			}

			// Build query with MIN function
			$query = "SELECT MIN({$column}) as minimum FROM {$this->froms}";

			// Add WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$query .= " WHERE " . join(" ", $whereParts);
			}

			// Execute query
			$result = $this->connector->execute($query);

			if ($result === false)
			{
				throw new DatabaseException("Min query failed: " . $this->connector->lastError(), 1);
			}

			// Get result
			$row = $result->fetch_assoc();

			return $row['minimum'];
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Find maximum column value
	 *
	 * Returns the largest value in a column.
	 *
	 * Examples:
	 *   max('price')
	 *   → 999.99
	 *
	 *   where('category', 'laptops')->max('price')
	 *   → 2499.99
	 *
	 * @param string $column Column name
	 * @return mixed Maximum value
	 */
	public function max($column)
	{
		try
		{
			if (empty($column))
			{
				throw new DatabaseException("No column provided for max operation", 1);
			}

			// Build query with MAX function
			$query = "SELECT MAX({$column}) as maximum FROM {$this->froms}";

			// Add WHERE clause if exists
			if (!empty($this->wheres))
			{
				$whereParts = array();

				foreach ($this->wheres as $index => $whereClause)
				{
					$condition = $whereClause['condition'];
					$operator = $whereClause['operator'];

					if ($index === 0)
					{
						$whereParts[] = $condition;
					}
					else
					{
						$whereParts[] = "{$operator} {$condition}";
					}
				}

				$query .= " WHERE " . join(" ", $whereParts);
			}

			// Execute query
			$result = $this->connector->execute($query);

			if ($result === false)
			{
				throw new DatabaseException("Max query failed: " . $this->connector->lastError(), 1);
			}

			// Get result
			$row = $result->fetch_assoc();

			return $row['maximum'];
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Compare two columns in WHERE clause
	 *
	 * Adds WHERE condition comparing two columns.
	 * Useful for checking relationships or duplicates.
	 *
	 * Examples:
	 *   whereColumn('first_name', 'last_name')
	 *   → WHERE first_name = last_name
	 *
	 *   whereColumn('created_at', '>', 'updated_at')
	 *   → WHERE created_at > updated_at
	 *
	 * @param string $column1 First column name
	 * @param string $operatorOrColumn2 Operator or second column name
	 * @param string|null $column2 Second column name (if operator provided)
	 * @return MySQLQuery Chainable query instance
	 */
	public function whereColumn($column1, $operatorOrColumn2, $column2 = null)
	{
		try
		{
			if (empty($column1))
			{
				throw new DatabaseException("No first column provided for whereColumn clause", 1);
			}

			// Two arguments: whereColumn('col1', 'col2') - defaults to =
			if ($column2 === null)
			{
				$operator = '=';
				$column2 = $operatorOrColumn2;
			}
			// Three arguments: whereColumn('col1', '>', 'col2')
			else
			{
				$operator = $operatorOrColumn2;
			}

			if (empty($column2))
			{
				throw new DatabaseException("No second column provided for whereColumn clause", 1);
			}

			// Build WHERE column comparison
			$this->wheres[] = array(
				'condition' => "{$column1} {$operator} {$column2}",
				'operator' => 'AND'
			);

			return $this;
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}

	/**
	 * Add GROUP BY clause
	 *
	 * Groups results by one or more columns.
	 * Typically used with aggregate functions (COUNT, SUM, AVG, etc.).
	 *
	 * Examples:
	 *   groupBy('category')
	 *   → GROUP BY category
	 *
	 *   groupBy('year', 'month')
	 *   → GROUP BY year, month
	 *
	 * @param string ...$columns Column names to group by
	 * @return $this For method chaining
	 * @throws DatabaseException If no columns provided
	 */
	public function groupBy(...$columns)
	{
		try
		{
			// Validate at least one column provided
			if (empty($columns))
			{
				throw new DatabaseException("No columns provided for GROUP BY clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Add columns to groups array
		foreach ($columns as $column)
		{
			$this->groups[] = $column;
		}

		return $this;
	}

	/**
	 * Add HAVING clause
	 *
	 * Filters grouped results (like WHERE but for GROUP BY).
	 * Must be used with GROUP BY.
	 *
	 * Examples:
	 *   having('COUNT(*) > ?', 5)
	 *   → HAVING COUNT(*) > 5
	 *
	 *   having('SUM(amount) >= ?', 1000)
	 *   → HAVING SUM(amount) >= 1000
	 *
	 * @param mixed ...$arguments Condition and values (same pattern as where)
	 * @return $this For method chaining
	 * @throws DatabaseException If invalid arguments
	 */
	public function having(...$arguments)
	{
		try
		{
			// Validate argument pairs match
			if (count($arguments) < 2 || count($arguments) % 2 != 0)
			{
				throw new DatabaseException("Invalid arguments for HAVING clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Single argument pair
		if (count($arguments) == 2)
		{
			// Replace ? with %s placeholder
			$arguments[0] = preg_replace("#\?#", "%s", $arguments[0]);

			// Quote the value
			$arguments[1] = $this->quote($arguments[1]);

			// Build HAVING clause
			$this->havings[] = call_user_func_array("sprintf", $arguments);

			return $this;
		}

		// Multiple argument pairs
		$count = count($arguments) / 2;

		for ($i = 0; $i < $count; $i++)
		{
			// Extract one pair
			$argumentsPair = array_splice($arguments, 0, 2);

			// Replace ? with %s placeholder
			$argumentsPair[0] = preg_replace("#\?#", "%s", $argumentsPair[0]);

			// Quote the value
			$argumentsPair[1] = $this->quote($argumentsPair[1]);

			// Build HAVING clause
			$this->havings[] = call_user_func_array("sprintf", $argumentsPair);
		}

		return $this;
	}

	/**
	 * Add INNER JOIN to query
	 *
	 * Joins another table and returns only matching records from both tables.
	 * More efficient than LEFT JOIN when you know relationships exist.
	 *
	 * Examples:
	 *   innerJoin('users', 'posts.user_id = users.id', ['users.name'])
	 *   innerJoin('categories', 'posts.category_id = categories.id', ['*'])
	 *
	 * @param string $table Table name to join
	 * @param string $condition Join condition (e.g., 'posts.user_id = users.id')
	 * @param array $fields Columns to select from joined table
	 * @return $this For method chaining
	 * @throws DatabaseException If table or condition is empty
	 */
	public function innerJoin($table, $condition, $fields = array("*"))
	{
		try
		{
			// Validate table is not empty
			if (empty($table))
			{
				throw new DatabaseException("Invalid table argument $table passed for the innerJoin Clause", 1);
			}

			// Validate condition is not empty
			if (empty($condition))
			{
				throw new DatabaseException("Invalid argument $condition passed for the innerJoin Clause", 1);
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Add fields for this table
		$this->fields += array($table => $fields);

		// Store join table, condition, and type
		$this->joins['tables'][] = $table;
		$this->joins['conditions'][] = $condition;
		$this->joins['types'][] = 'INNER';  // Mark as INNER JOIN

		return $this;
	}

	// =========================================================================
	// BUILD METHODS (Construct SQL Strings)
	// =========================================================================

	/**
	 * Build SELECT query string
	 *
	 * Constructs SELECT query from stored parameters.
	 * Handles fields, joins, where, group by, having, order, and limit clauses.
	 *
	 * Query Template:
	 *   SELECT [DISTINCT] fields FROM table [JOIN] [WHERE] [GROUP BY] [HAVING] [ORDER BY] [LIMIT]
	 *
	 * @return string Complete SELECT query
	 */
	protected function buildSelect()
	{
		$fields = array();
		$where = $order = $limit = $join = $group = $having = "";
		$template = "SELECT %s %s FROM %s %s %s %s %s %s %s";

		// Build fields list
		foreach ($this->fields as $table => $tableFields)
		{
			foreach ($tableFields as $field => $alias)
			{
				// Field with alias
				if (is_string($field) && $field != 'COUNT(1)')
				{
					$fields[] = "{$table}.{$field} AS {$alias}";
				}
				// COUNT function
				elseif (is_string($field) && $field == 'COUNT(1)')
				{
					$fields[] = "{$field} AS {$alias}";
				}
				// Field without alias
				else
				{
					$fields[] = "{$table}.{$alias}";
				}
			}
		}

		// Convert fields array to string
		$fields = join(", ", $fields);

		// Build JOIN clauses (supports multiple joins with different types)
		$queryJoin = $this->joins;

		if (!empty($queryJoin))
		{
			$joinParts = array();

			// Build each join separately to support different join types
			foreach ($queryJoin['tables'] as $index => $table)
			{
				$condition = $queryJoin['conditions'][$index];
				$type = isset($queryJoin['types'][$index]) ? $queryJoin['types'][$index] : 'LEFT';

				$joinParts[] = "{$type} JOIN {$table} ON {$condition}";
			}

			$join = " " . join(" ", $joinParts);
		}

		// Build WHERE clause (supports AND/OR operators)
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$whereParts = array();

			foreach ($queryWhere as $index => $whereClause)
			{
				$condition = $whereClause['condition'];
				$operator = $whereClause['operator'];

				// First condition has no operator prefix
				if ($index === 0)
				{
					$whereParts[] = $condition;
				}
				// Subsequent conditions use their operator
				else
				{
					$whereParts[] = "{$operator} {$condition}";
				}
			}

			$joined = join(" ", $whereParts);

			$where = "WHERE {$joined}";
		}

		// Build GROUP BY clause
		$queryGroup = $this->groups;

		if (!empty($queryGroup))
		{
			$group = "GROUP BY " . join(", ", $queryGroup);
		}

		// Build HAVING clause
		$queryHaving = $this->havings;

		if (!empty($queryHaving))
		{
			$joined = join(" AND ", $queryHaving);

			$having = "HAVING {$joined}";
		}

		// Build ORDER BY clause (supports multiple columns)
		$queryOrder = $this->orders;

		if (!empty($queryOrder))
		{
			$orderParts = array();

			foreach ($queryOrder as $column => $direction)
			{
				$orderParts[] = "{$column} {$direction}";
			}

			$order = "ORDER BY " . join(", ", $orderParts);
		}

		// Build LIMIT clause
		$queryLimit = $this->limits;

		if (!empty($queryLimit))
		{
			$limitOffset = $this->offset;

			// With offset (pagination)
			if ($limitOffset)
			{
				$limit = "LIMIT {$limitOffset}, {$queryLimit}";
			}
			// Without offset
			else
			{
				$limit = "LIMIT {$queryLimit}";
			}
		}

		// Return complete query
		return sprintf($template, $this->distinct, $fields, $this->froms, $join, $where, $group, $having, $order, $limit);
	}

	/**
	 * Build INSERT query string
	 *
	 * Constructs INSERT query for single row.
	 * Optionally sets date_created timestamp.
	 *
	 * Query Template:
	 *   INSERT INTO table (fields) VALUES (values)
	 *
	 * @param array $data Associative array of field => value pairs
	 * @param bool $set_timestamps Whether to set date_created field
	 * @return string Complete INSERT query
	 */
	protected function buildInsert($data, $set_timestamps)
	{
		$fields = array();
		$values = array();
		$template = "INSERT INTO %s (%s) VALUES (%s)";

		// Add timestamp if requested
		if($set_timestamps)
		{
			$data['date_created'] = date('Y-m-d h:i:s');
		}

		// Build fields and values
		foreach ($data as $field => $value)
		{
			$fields[] = $field;
			$values[] = $this->quote($value);
		}

		// Convert arrays to strings
		$fields = join(", ", $fields);
		$values = join(", ", $values);

		// Return complete query
		return sprintf($template, $this->froms, $fields, $values);
	}

	/**
	 * Build bulk INSERT query string
	 *
	 * Constructs INSERT query for multiple rows.
	 * More efficient than multiple single inserts.
	 *
	 * Query Template:
	 *   INSERT INTO table (fields) VALUES (row1), (row2), (row3)
	 *
	 * @param array $data Multidimensional array of rows
	 * @param bool $set_timestamps Whether to set date_created field
	 * @return string Complete INSERT query
	 */
	protected function buildBulkInsert($data, $set_timestamps)
	{
		$fields = array();
		$values = array();
		$template = "INSERT INTO %s (%s) VALUES %s";

		// Get field names from first row
		$fieldsArray = $data[0];

		foreach ($fieldsArray as $field => $value)
		{
			$fields[] = $field;
		}

		// Get count of rows
		$count = sizeof($data);

		// Build values for each row
		for ($i = 0; $i < $count; $i++)
		{
			$array = $data[$i];
			$valuesArray = array();

			// Quote each value
			foreach ($array as $field => $value)
			{
				$valuesArray[] = $this->quote($value);
			}

			// Group row values in parentheses
			$values[] = '(' . join(", ", $valuesArray) . ')';
		}

		// Convert arrays to strings
		$fields = join(", ", $fields);
		$values = join(", ", $values);

		// Return complete query
		return sprintf($template, $this->froms, $fields, $values);
	}

	/**
	 * Build UPDATE query string
	 *
	 * Constructs UPDATE query for single or multiple records.
	 * Uses WHERE clause to target specific records.
	 * Optionally sets date_modified timestamp.
	 *
	 * Query Template:
	 *   UPDATE table SET field1=value1, field2=value2 WHERE conditions LIMIT n
	 *
	 * @param array $data Associative array of field => value pairs to update
	 * @param bool $set_timestamps Whether to set date_modified field
	 * @return string Complete UPDATE query
	 */
	protected function buildUpdate($data, $set_timestamps)
	{
		$parts = array();
		$where = $limit = '';
		$template = "UPDATE %s SET %s %s %s";

		// Add timestamp if requested
		if($set_timestamps)
		{
			$data['date_modified'] = date('Y-m-d h:i:s');
		}

		// Build SET clause
		foreach ($data as $field => $value)
		{
			$parts[] = "{$field}=" . $this->quote($value);
		}

		// Convert to string
		$parts = join(", ", $parts);

		// Build WHERE clause (supports AND/OR operators)
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$whereParts = array();

			foreach ($queryWhere as $index => $whereClause)
			{
				$condition = $whereClause['condition'];
				$operator = $whereClause['operator'];

				// First condition has no operator prefix
				if ($index === 0)
				{
					$whereParts[] = $condition;
				}
				// Subsequent conditions use their operator
				else
				{
					$whereParts[] = "{$operator} {$condition}";
				}
			}

			$joined = join(" ", $whereParts);

			$where = "WHERE {$joined}";
		}

		// Build LIMIT clause
		$queryLimit = $this->limits;

		if (!empty($queryLimit))
		{
			$limitOffset = $this->offset;

			$limit = "LIMIT {$queryLimit} {$limitOffset}";
		}

		// Return complete query
		return sprintf($template, $this->froms, $parts, $where, $limit);
	}

	/**
	 * Build bulk UPDATE query string
	 *
	 * Constructs UPDATE query for multiple records using CASE WHEN pattern.
	 * Updates multiple rows with different values in a single query.
	 *
	 * Query Template:
	 *   UPDATE table
	 *   SET field1 = (CASE id WHEN 1 THEN 'value1' WHEN 2 THEN 'value2' END),
	 *       field2 = (CASE id WHEN 1 THEN 'value3' WHEN 2 THEN 'value4' END)
	 *   WHERE id IN (1, 2)
	 *
	 * @param array $data Multidimensional array indexed by ID
	 * @param array $fields Field names to update
	 * @param array $ids ID values for WHERE IN clause
	 * @param string $key Primary key column name (e.g., 'id')
	 * @return string Complete UPDATE query
	 */
	protected function buildBulkUpdate($data, $fields, $ids, $key)
	{
		$parts = array();
		$template = "UPDATE %s SET %s WHERE %s IN (%s) ";

		// Build CASE WHEN for each field
		foreach ($fields as $index => $field)
		{
			// Start CASE statement
			$subparts = $field . ' = (CASE ' . $key . ' ';

			// Add WHEN clause for each ID
			foreach ($data as $id => $info)
			{
	            if (!empty($info))
	            {
					$subparts .=  ' WHEN '. $id . ' THEN ' . $this->quote($info[$field]) . ' ';
	            }
			}

			// End CASE statement
			$subparts .= ' END) ';

			$parts[] = $subparts;
		}

		// Convert parts to string
		$parts = join(", ", $parts);

		// Build WHERE IN clause
		$queryWhere = $ids;

		if (!empty($queryWhere))
		{
			$where = join(", ", $queryWhere);
		}

		// Return complete query
		return sprintf($template, $this->froms, $parts, $key, $where);
	}

	/**
	 * Build DELETE query string
	 *
	 * Constructs DELETE query.
	 * Uses WHERE clause to target specific records.
	 *
	 * Query Template:
	 *   DELETE FROM table WHERE conditions LIMIT n
	 *
	 * @return string Complete DELETE query
	 */
	protected function buildDelete()
	{
		$where = $limit = '';
		$template = "DELETE FROM %s %s %s";

		// Build WHERE clause (supports AND/OR operators)
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$whereParts = array();

			foreach ($queryWhere as $index => $whereClause)
			{
				$condition = $whereClause['condition'];
				$operator = $whereClause['operator'];

				// First condition has no operator prefix
				if ($index === 0)
				{
					$whereParts[] = $condition;
				}
				// Subsequent conditions use their operator
				else
				{
					$whereParts[] = "{$operator} {$condition}";
				}
			}

			$joined = join(" ", $whereParts);

			$where = "WHERE {$joined}";
		}

		// Build LIMIT clause
		$queryLimit = $this->limits;

		if (!empty($queryLimit))
		{
			$limitOffset = $this->offset;

			$limit = "LIMIT {$queryLimit} {$limitOffset}";
		}

		// Return complete query
		return sprintf($template, $this->froms, $where, $limit);
	}

	// =========================================================================
	// EXECUTION METHODS (Execute Queries)
	// =========================================================================

	/**
	 * Execute INSERT or UPDATE query
	 *
	 * Determines whether to INSERT or UPDATE based on WHERE clause.
	 * If WHERE clause is empty, performs INSERT.
	 * If WHERE clause is set, performs UPDATE.
	 *
	 * @param array $data Data to insert/update
	 * @param bool $set_timestamps Whether to set date_created/date_modified
	 * @return MySQLResponse Response with insert ID or affected rows
	 * @throws DatabaseException If query execution fails
	 */
	public function save($data, $set_timestamps)
	{
		// Determine if this is insert or update
		$doInsert = sizeof($this->wheres) == 0;

		// Build query
		if ($doInsert)
		{
			$sql = $this->buildInsert($data, $set_timestamps);
		}
		else
		{
			$sql = $this->buildUpdate($data, $set_timestamps);
		}

		// Store query string in response object
		$this->responseObject->setQueryString($sql);

		// Time the query execution
		$query_start_time = microtime(true);

		// Execute query
		$result = $this->connector->execute($sql);

		$query_stop_time = microtime(true);
		$query_excec_time = $query_stop_time - $query_start_time;

		// Store execution time
		$this->responseObject->setQueryTime($query_excec_time);

		try
		{
			// Check for query error
			if ($result === false)
			{
				throw new DatabaseException(get_class(new DatabaseException) . ' ' .$this->connector->lastError() . '<span class="query-string"> (' . $sql . ') </span>');
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Return response based on operation type
		if($doInsert)
		{
			// INSERT - return insert ID
			$this->responseObject->setLastInsertId($this->connector->lastInsertId());

			return $this->responseObject;
		}
		else
		{
			// UPDATE - return affected rows
			$this->responseObject
				->setUpdateSuccess(true)
				->setAffectedRows($this->connector->affectedRows());

			return $this->responseObject;
		}
	}

	/**
	 * Execute bulk INSERT or UPDATE query
	 *
	 * Determines whether to INSERT or UPDATE based on WHERE clause.
	 * More efficient than multiple save() calls.
	 *
	 * @param array $data Multidimensional array of records
	 * @param array $fields Field names (for bulk update)
	 * @param array $ids ID values (for bulk update)
	 * @param string $key Primary key name (for bulk update)
	 * @param bool $set_timestamps Whether to set timestamps
	 * @return MySQLResponse Response with insert ID or affected rows
	 * @throws DatabaseException If query execution fails
	 */
	public function saveBulk($data, $fields = null, $ids = null, $key = null, $set_timestamps)
	{
		// Determine if this is insert or update
		$doInsert = sizeof($this->wheres) == 0;

		// Build query
		if ($doInsert)
		{
			$sql = $this->buildBulkInsert($data, $set_timestamps);
		}
		else
		{
			$sql = $this->buildBulkUpdate($data, $fields, $ids, $key, $set_timestamps);
		}

		// Store query string in response object
		$this->responseObject->setQueryString($sql);

		// Time the query execution
		$query_start_time = microtime(true);

		// Execute query
		$result = $this->connector->execute($sql);

		$query_stop_time = microtime(true);
		$query_excec_time = $query_stop_time - $query_start_time;

		// Store execution time (FIXED TYPO: was $this->setQueryTime)
		$this->responseObject->setQueryTime($query_excec_time);

		try
		{
			// Check for query error
			if ($result === false)
			{
				throw new DatabaseException(get_class(new DatabaseException) . ' ' .$this->connector->lastError() . '<span class="query-string"> (' . $sql . ') </span>');
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Return response based on operation type
		if($doInsert)
		{
			// INSERT - return insert ID
			$this->responseObject->setLastInsertId($this->connector->lastInsertId());

			return $this->responseObject;
		}
		else
		{
			// UPDATE - return affected rows
			$this->responseObject
				->setUpdateSuccess(true)
				->setAffectedRows($this->connector->affectedRows());

			return $this->responseObject;
		}
	}

	/**
	 * Execute DELETE query
	 *
	 * Deletes records matching WHERE conditions.
	 *
	 * @return MySQLResponse Response with affected rows count
	 * @throws DatabaseException If query execution fails
	 */
	public function delete()
	{
		// Build DELETE query
		$sql = $this->buildDelete();

		// Store query string
		$this->responseObject->setQueryString($sql);

		// Time the query execution
		$query_start_time = microtime(true);

		// Execute query
		$result = $this->connector->execute($sql);

		$query_stop_time = microtime(true);
		$query_excec_time = $query_stop_time - $query_start_time;

		// Store execution time
		$this->responseObject->setQueryTime($query_excec_time);

		try
		{
			// Check for query error
			if ($result === false)
			{
				throw new DatabaseException(get_class(new DatabaseException) . ' ' .$this->connector->lastError() . '<span class="query-string"> (' . $sql . ') </span>');
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Return affected rows count
		$this->responseObject->setAffectedRows($this->connector->affectedRows());

		return $this->responseObject;
	}

	/**
	 * Get first result from query
	 *
	 * Executes query with LIMIT 1 and returns first row.
	 * Preserves original limit/offset settings.
	 *
	 * @return MySQLResponse Response with single result
	 */
	public function first()
	{
		// Save original limit settings
		$limit = $this->limits;
		$limitOffset = $this->offset;

		// Set limit to 1
		$this->limit(1);

		// Get all results (will be 1 row)
		$all = $this->all();

		// Extract first result
		$first = ArrayUtility::first($all->result_array())->get();

		// Restore original limit settings
		if ($limit)
		{
			$this->limits = $limit;
		}

		if ($limitOffset)
		{
			$this->offset = $limitOffset;
		}

		// Store result in response object
		$this->responseObject->setResultArray($first);

		return $this->responseObject;
	}

	/**
	 * Count matching records
	 *
	 * Executes COUNT(1) query to get number of matching rows.
	 * More efficient than fetching all rows and counting.
	 *
	 * @return MySQLResponse Response with row count
	 */
	public function count()
	{
		// Save original query settings
		$limit = $this->limits;
		$limitOffset = $this->offset;
		$fields = $this->fields;

		// Change to COUNT query
		$this->fields = array($this->froms => array("COUNT(1)" => "rows"));

		// Set limit to 1
		$this->limit(1);

		// Get count
		$row = $this->first()->result_array();

		// Restore original field settings
		$this->fields = $fields;

		if ($fields)
		{
			$this->fields = $fields;
		}

		// Restore original limit settings
		if ($limit)
		{
			$this->limits = $limit;
		}

		if ($limitOffset)
		{
			$this->offset = $limitOffset;
		}

		// Store count in response object
		$this->responseObject
			->setNumRows($row[0]['rows'])
			->setResultArray($row);

		return $this->responseObject;
	}

	/**
	 * Get all matching results
	 *
	 * Executes SELECT query and returns all matching rows.
	 *
	 * @return MySQLResponse Response with all results and metadata
	 * @throws DatabaseException If query execution fails
	 */
	public function all()
	{
		// Build SELECT query
		$sql = $this->buildSelect();

		// Store query string
		$this->query_string = $sql;

		// Time the query execution
		$query_start_time = microtime(true);

		// Execute query
		$result = $this->connector->execute($sql);

		$query_stop_time = microtime(true);
		$query_excec_time = $query_stop_time - $query_start_time;

		try
		{
			// Check for query error
			if ($result === false)
			{
				$error = $this->connector->lastError();

				throw new DatabaseException(get_class(new DatabaseException) . ' ' .$this->connector->lastError() . '<span class="query-string"> (' . $sql . ') </span>');
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}

		// Fetch all rows
		$result_array = array();

		while ($row = $result->fetch_array(MYSQLI_ASSOC))
		{
			$result_array[] = $row;
		}

		// Store results and metadata in response object
		$this->responseObject
			->setQueryString($this->query_string)
			->setQueryTime($query_excec_time)
			->setFieldCount($result->field_count)
			->setNumRows($result->num_rows)
			->setQueryFields($result->fetch_fields())
			->setResultArray($result_array);

		return $this->responseObject;
	}

	/**
	 * Execute raw SQL query
	 *
	 * Executes custom SQL when query builder is insufficient.
	 * Use for complex queries, stored procedures, etc.
	 *
	 * WARNING: Ensure proper escaping to prevent SQL injection!
	 *
	 * @param string $query_string SQL query to execute
	 * @return mixed Query result or response object
	 * @throws DatabaseException If query execution fails
	 */
	public function rawQuery($query_string)
	{
		try
		{
			// Execute query
		 	$result = $this->connector->execute($query_string);

			// Check for query error
			if ($result === false)
			{
				throw new DatabaseException(get_class(new DatabaseException) . ' ' .$this->connector->lastError() . '<span class="query-string"> (' . $query_string . ') </span>');
			}
			else
			{
				return $result;
			}
		}
		catch(DatabaseException $DatabaseExceptionObject)
		{
			$DatabaseExceptionObject->errorShow();
		}
	}
}
