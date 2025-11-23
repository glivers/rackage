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
 *   - Results are returned via MySQLResponseObject
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
 *       → Returns MySQLResponseObject with results
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
use Rackage\Database\MySQL\MySQLResultObject;
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
	 * Column name to sort by
	 * @var string
	 */
	protected $orders;

	/**
	 * DISTINCT keyword for unique results
	 * @var string
	 */
	protected $distinct = ' ';

	/**
	 * Sort direction (ASC or DESC)
	 * @var string
	 */
	protected $directions;

	/**
	 * JOIN clauses (tables and conditions)
	 * @var array
	 */
	protected $joins = array();

	/**
	 * WHERE conditions
	 * @var array
	 */
	protected $wheres = array();

	/**
	 * Query response object
	 * @var MySQLResponseObject
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
		$this->responseObject = new MySQLResponseObject();
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

		// Store join table and condition
		$this->joins['tables'][] = $table;
		$this->joins['conditions'][] = $condition;

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
	 * Set ORDER BY clause
	 *
	 * Specifies how to sort query results.
	 *
	 * Examples:
	 *   order('created_at', 'desc')  → ORDER BY created_at desc
	 *   order('name', 'asc')         → ORDER BY name asc
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

		// Set order column and direction
		$this->orders = $order;
		$this->directions = $direction;

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

			// Build WHERE clause with sprintf
			$this->wheres[] = call_user_func_array("sprintf", $arguments);

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

				// Build WHERE clause with sprintf
				$this->wheres[] = call_user_func_array("sprintf", $argumentsPair);
			}

			return $this;
		}
	}

	// =========================================================================
	// BUILD METHODS (Construct SQL Strings)
	// =========================================================================

	/**
	 * Build SELECT query string
	 *
	 * Constructs SELECT query from stored parameters.
	 * Handles fields, joins, where, order, and limit clauses.
	 *
	 * Query Template:
	 *   SELECT [DISTINCT] fields FROM table [JOIN] [WHERE] [ORDER BY] [LIMIT]
	 *
	 * @return string Complete SELECT query
	 */
	protected function buildSelect()
	{
		$fields = array();
		$where = $order = $limit = $join = "";
		$template = "SELECT %s %s FROM %s %s %s %s %s";

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

		// Build JOIN clause
		$queryJoin = $this->joins;

		if (!empty($queryJoin))
		{
			$joinTables = "(" . join(", ", $queryJoin['tables']) . ")";
			$joinConditions = "(" . join(" AND ", $queryJoin['conditions']) . ")";

			$join = " LEFT JOIN $joinTables ON $joinConditions";
		}

		// Build WHERE clause
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$joined = join(" AND ", $queryWhere);

			$where = "WHERE {$joined}";
		}

		// Build ORDER BY clause
		$queryOrder = $this->orders;

		if (!empty($queryOrder))
		{
			$orderDirection = $this->directions;

			$order = "ORDER BY {$queryOrder} {$orderDirection}";
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
		return sprintf($template, $this->distinct, $fields, $this->froms, $join, $where, $order, $limit);
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

		// Build WHERE clause
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$joined = join(" AND ", $queryWhere);

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

		// Build WHERE clause
		$queryWhere = $this->wheres;

		if (!empty($queryWhere))
		{
			$joined = join(" AND ", $queryWhere);

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
	 * @return MySQLResponseObject Response with insert ID or affected rows
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
	 * @return MySQLResponseObject Response with insert ID or affected rows
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
	 * @return MySQLResponseObject Response with affected rows count
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
	 * @return MySQLResponseObject Response with single result
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
	 * @return MySQLResponseObject Response with row count
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
	 * @return MySQLResponseObject Response with all results and metadata
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
