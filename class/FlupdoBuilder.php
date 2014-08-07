<?php
/*
 * Copyright (c) 2013, Josef Kufner  <jk@frozen-doe.net>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Flupdo\Flupdo;

/**
 * Query builder base class, which provides some magic to build queries.
 */
abstract class FlupdoBuilder
{
	/**
	 * PDO driver used to execute query and escape strings.
	 */
	protected $pdo;

	/**
	 * Indentation string.
	 */
	protected $indent = "\t";

	/**
	 * Second level indentation string.
	 */
	protected $sub_indent = "\t\t";

	/**
	 * Built query
	 */
	protected $query_sql = null;

	/**
	 * Parameters for prepared statement (to be bound before query is executed).
	 */
	protected $query_params = null;

	/**
	 * List of clauses used to composed result query. Shared constant data.
	 */
	protected static $clauses = array();

	/**
	 * List of methods used to fill the $buffers. Shared constant data.
	 */
	protected static $methods = array();

	/**
	 * Buffers containing SQL fragments.
	 */
	protected $buffers = array();


	/**
	 * @name Flags for helper methods.
	 * 
	 * Used by sqlList() and sqlStatementFlags().
	 * @{
	 */
	const INDENT		= 0x01;	///< List items should be indented.
	const LABEL		= 0x02;	///< SQL fragment has a label.
	const BRACKETS		= 0x04;	///< There are brackets around each item in the list.
	const EOL		= 0x80;	///< Add EOL after the SQL fragment.
	const ALL_DECORATIONS	= 0xFF;	///< Make it fancy!
	/** @} */


	/**
	 * Constructor.
	 */
	public function __construct($pdo)
	{
		$this->pdo = $pdo;
	}


	/**
	 * Call buffer-specific method to process arguments.
	 *
	 * If the first argument is null, corresponding buffer will be deleted.
	 */
	public function __call($method, $args)
	{
		//echo __CLASS__, "::", $method, " (", join(', ', array_map(function($x) { return var_export($x, true);}, $args)), ")\n";

		if (!isset(static::$methods[$method])) {
			throw new \BadMethodCallException('Undefined method "'.$method.'".');
		}

		if ($this->query_sql !== null) {
			throw new \RuntimeException('Query is already compiled.');
		}

		@ list($action, $buffer_id, $label) = static::$methods[$method];

		if (count($args) == 1 && $args[0] === null) {
			unset($this->buffers[$buffer_id]);
		} else {
			$this->$action($args, $buffer_id, $label);
		}

		$this->query_sql = null;

		return $this;
	}


	/**
	 * Quote `identifier`.
	 */
	public function quoteIdent($ident)
	{
		if (is_array($ident)) {
			return array_map(function($ident) { return str_replace("`", "``", $ident); }, $ident);
		} else {
			return str_replace("`", "``", $ident);
		}
	}


	/**
	 * Add SQL fragment to buffer.
	 */
	protected function add($args, $buffer_id)
	{
		$this->buffers[$buffer_id][] = $args;
	}


	/**
	 * Replace buffer content with SQL fragment.
	 */
	protected function replace($args, $buffer_id)
	{
		$this->buffers[$buffer_id] = array($args);
	}


	/**
	 * Set flag. Replace buffer with new label of this flag.
	 */
	protected function setFlag($args, $buffer_id, $label)
	{
		$this->buffers[$buffer_id] = $label;
	}


	/**
	 * Add join statement to buffer.
	 */
	protected function addJoin($args, $buffer_id, $label)
	{
		array_push($args, $label);
		$this->buffers[$buffer_id][] = $args;
	}


	/**
	 * Process all buffers and build SQL query. Side product is array of
	 * parameters (stored in $this->args) to bind with query.
	 *
	 * This function is called by FlupdoBuilder, do not call it directly.
	 *
	 * Example:
	 *
	 *     $this->sqlStart();
	 *     // ...
	 *     return $this->sqlFinish();
	 *
	 */
	abstract protected function compileQuery();


	/**
	 * Call compile function in a safe way.
	 */
	public final function compile()
	{
		try {
			return $this->compileQuery();
		}
		catch (\Exception $ex) {
			// Make sure unfinished query will not make it to the output.
			ob_end_clean();
			throw $ex;
		}
	}


	/**
	 * Fluently dump query to error log.
	 */
	public function debugDump()
	{
		error_log("Query:\n".$this);
		return $this;
	}


	/**
	 * Get compiled SQL query, use only for debugging.
	 */
	public function getSqlQuery()
	{
		if ($this->query_sql === null) {
			$this->compile();
		}

		return $this->query_sql;
	}


	/**
	 * Get parameters for compiled SQL query, use only for debugging.
	 */
	public function getSqlParams()
	{
		if ($this->query_sql === null) {
			$this->compile();
		}

		return $this->query_params;
	}


	/**
	 * Quotes a string for use in a query.
	 *
	 * Proxy to PDO::quote().
	 */
	public function quote($value)
	{
		// PDO::quote() does not work as it should ...
		if ($value instanceof FlupdoRawSql) {
			return $value;
		} else if (is_bool($value)) {
			return $value ? 'TRUE' : 'FALSE';
		} else if (is_null($value)) {
			return 'NULL';
		} else if (is_int($value)) {
			return (string) $value;
		} else if (is_float($value)) {
			return sprintf('%F', $value);
		} else {
			// ignore locales when converting to string
			return $this->pdo->quote(strval($value), \PDO::PARAM_STR);
		}
	}


	/**
	 * Returns object marking raw SQL statement.
	 */
	public function rawSql($sql)
	{
		return new FlupdoRawSql($sql);
	}


	/**
	 * Builds and executes an SQL statement, returning the number of affected rows.
	 *
	 * Proxy to PDO::exec().
	 */
	public function exec()
	{
		if ($this->query_sql === null) {
			$this->compile();
		}
		debug_msg("SQL Query:\n%s", $this->query_sql);
		if (empty($this->query_params)) {
			$r = $this->pdo->exec($this->query_sql);
			if ($r === FALSE) {
				throw new FlupdoSqlException($this->pdo->errorInfo(), $this->query_sql, $this->query_params);
			}
			return $r;
		} else {
			$stmt = $this->query();
			return $stmt->rowCount();
		}
	}


	/**
	 * Builds, binds and executes an SQL statement, returning a result set
	 * as a PDOStatement object.
	 *
	 * Proxy to PDOStatement::prepare() & PDOStatement::bindValue() & PDOStatement::query().
	 * But if there is nothing to bind, PDO::query() is called instead.
	 */
	public function query()
	{
		if ($this->query_sql === null) {
			$this->compile();
		}

		debug_msg("SQL Query:\n%s", $this->query_sql);
		if (empty($this->query_params)) {
			$result = $this->pdo->query($this->query_sql);
			if (!$result) {
				throw new FlupdoSqlException($this->pdo->errorInfo(), $this->query_sql, $this->query_params);
			}
			return $result;
		} else {
			$stmt = $this->prepare();
			if ($stmt === FALSE) {
				throw new FlupdoSqlException($this->pdo->errorInfo(), $this->query_sql, $this->query_params);
			}

			$i = 1;
			foreach ($this->query_params as $param) {
				if (is_bool($param)) {
					$stmt->bindValue($i, $param, \PDO::PARAM_BOOL);
				} else if (is_null($param)) {
					$stmt->bindValue($i, $param, \PDO::PARAM_NULL);
				} else if (is_int($param)) {
					$stmt->bindValue($i, $param, \PDO::PARAM_INT);
				} else {
					// ignore locales when converting to string
					$stmt->bindValue($i, strval($param), \PDO::PARAM_STR);
				}
				$i++;
			}

			if ($stmt->execute() === FALSE) {
				throw new FlupdoSqlException($stmt->errorInfo(), $this->query_sql, $this->query_params);
			}
			return $stmt;
		}
	}


	/**
	 * Builds and prepares a statement for execution, returns a statement object.
	 *
	 * Proxy to PDO::prepare().
	 */
	public function prepare($driver_options = array())
	{
		if ($this->query_sql === null) {
			$this->compile();
		}

		return $this->pdo->prepare($this->query_sql, $driver_options);
	}


	/**
	 * Proxy to PDO::lastInsertId().
	 */
	public function lastInsertId()
	{
		return $this->pdo->lastInsertId();
	}


	/**
	 * Fetch one row from result and close cursor.
	 *
	 * Returns what PDOStatement::fetch() would return.
	 */
	public function fetchSingleRow()
	{
		$result = $this->query();
		$row = $result->fetch(\PDO::FETCH_ASSOC);
		$result->closeCursor();
		return $row;
	}


	/**
	 * Fetch one row from result and close cursor.
	 *
	 * Returns what PDOStatement::fetch() would return.
	 */
	public function fetchSingleValue()
	{
		$result = $this->query();
		$value = $result->fetchColumn(0);
		$result->closeCursor();
		return $value;
	}


	/**
	 * Get SQL query as a string.
	 */
	public function __toString()
	{
		try {
			if ($this->query_sql === null) {
				$this->compile();
			}
		}
		catch (\Exception $ex) {
			// __toString() cannot throw an exception, so we will
			// log it and die, fatal error would be triggered anyway.
			error_log(__METHOD__.': '.$ex);
			die();
		}
		return $this->query_sql;
	}


	/**
	 * Start SQL generating. Uses output buffering to concatenate the query.
	 */
	protected function sqlStart()
	{
		$this->query_params = array();
		ob_start();
	}


	/**
	 * Finish SQL generating. Picks up the query from output buffer.
	 */
	protected function sqlFinish()
	{
		$this->query_sql = ob_get_clean();

		// Flatten parameters before bind
		if (!empty($this->query_params)) {
			$this->query_params = call_user_func_array('array_merge', $this->query_params);
		}
		return $this;
	}


	/**
	 * Add SQL with parameters. Parameters are stored in groups, merge to
	 * one array is done at the end (using single array_merge call).
	 */
	protected function sqlBuffer($buf)
	{
		if (empty($buf)) {
			return;
		}

		$sql = array_shift($buf);

		if (is_array($sql)) {
			$first = true;
			foreach ($sql as $fragment) {
				if ($first) {
					$first = false;
				} else {
					echo ' ';
				}
				if ($fragment instanceof self) {
					$fragment->indent = $this->sub_indent."\t";
					$fragment->compile();
					echo "(\n", $fragment->query_sql, $this->sub_indent, ")";
					$this->query_params[] = $fragment->query_params;
				} else {
					echo $fragment;
				}
			}
		} else {
			echo $sql;
		}

		if (!empty($buf)) {
			$this->query_params[] = $buf;
		}
	}


	/**
	 * Generate raw SQL fragment.
	 */
	protected function sqlRawBuffer($buf)
	{
		if (is_array($buf[0])) {
			echo join("\n", $buf[0]);
		} else {
			echo $buf[0];
		}
	}


	/**
	 * Generate SQL comment.
	 */
	protected function sqlComment($buffer_id)
	{
		if (isset($this->buffers[$buffer_id])) {
			foreach ($this->buffers[$buffer_id] as $buf) {
				echo $this->indent, '-- ', str_replace(array("\r", "\n"), array('', "\n".$this->indent.'-- '), $this->sqlRawBuffer($buf)), "\n";
			}
		}
	}


	/**
	 * Generate flag fragment.
	 */
	protected function sqlFlag($buffer_id)
	{
		if (isset($this->buffers[$buffer_id])) {
			if (isset($this->buffers[$flag_buf])) {
				echo ' ', $this->buffers[$flag_buf];
			}
		}
	}


	/**
	 * Generate SQL fragment made of flags.
	 */
	protected function sqlStatementFlags($buffer_id, $flag_buffer_ids, $decorations)
	{
		$first = false;

		if ($decorations & self::INDENT) {
			echo $this->indent;
			$first = true;
		}

		if ($decorations & self::LABEL) {
			if ($first) {
				$first = false;
			} else {
				echo ' ';
			}
			echo $buffer_id;
			$first = false;
		}

		foreach ($flag_buffer_ids as $flag_buf) {
			if (isset($this->buffers[$flag_buf])) {
				if ($first) {
					$first = false;
				} else {
					echo ' ';
				}
				echo $this->buffers[$flag_buf];
			}
		}

		if ($decorations & self::EOL) {
			echo "\n";
		}
	}


	/**
	 * Generate SQL fragment made of list.
	 */
	protected function sqlList($buffer_id, $decorations)
	{
		$first = true;

		if (isset($this->buffers[$buffer_id])) {
			if ($decorations & self::INDENT) {
				if ($decorations & self::BRACKETS) {
					echo $this->sub_indent;
				} else {
					echo $this->indent;
				}
			} else if ($decorations & (self::LABEL | self::BRACKETS)) {
				echo ' ';
			}
			if ($decorations & self::LABEL) {
				echo $buffer_id;
			}
			if ($decorations & self::BRACKETS) {
				echo '(';
			}
			foreach ($this->buffers[$buffer_id] as $buf) {
				if ($decorations & self::BRACKETS) {
					if ($first) {
						$first = false;
					} else {
						echo ", ";
					}
				} else {
					if ($first) {
						$first = false;
						echo ' ';
					} else {
						echo ",\n", $this->sub_indent;
					}
				}
				$this->sqlBuffer($buf);
			}
			if ($decorations & self::BRACKETS) {
				echo ')';
			}
			if ($decorations & self::EOL) {
				echo "\n";
			}
		}
	}


	/**
	 * Generate SQL fragment made of list values.
	 */
	protected function sqlValuesList($buffer_id)
	{
		$first = true;

		if (isset($this->buffers[$buffer_id])) {
			echo $this->indent, $buffer_id, "\n";
			foreach ($this->buffers[$buffer_id] as $buf) {
				if (count($buf) == 1) {
					// One argument -- insert values from array
					foreach ($buf[0] as $row) {
						if ($first) {
							$first = false;
							echo $this->sub_indent, '(';
						} else {
							echo "),\n", $this->sub_indent, '(';
						}

						echo join(', ', array_map(array($this, 'quote'), $row)); // FIXME: bind values
					}
				} else {
					throw new \Exception('Not implemented yet.');
				}
			}
			echo ')';
			echo "\n";
		}
	}


	/**
	 * Generate SQL fragment made of joins.
	 */
	protected function sqlJoins($buffer_id)
	{
		$first = true;

		if (isset($this->buffers[$buffer_id])) {
			foreach ($this->buffers[$buffer_id] as $buf) {
				$join = array_pop($buf);
				echo $this->indent, $join, " ", $this->sqlBuffer($buf), "\n";
			}
		}
	}


	/**
	 * Generate SQL fragment made of conditions in AND statement.
	 */
	protected function sqlConditions($buffer_id)
	{
		$first = true;

		if (isset($this->buffers[$buffer_id])) {
			echo $this->indent, $buffer_id;
			foreach ($this->buffers[$buffer_id] as $buf) {
				if ($first) {
					$first = false;
					echo ' (';
				} else {
					echo $this->sub_indent, "AND (";
				}
				echo $this->sqlBuffer($buf), ")\n";
			}
		}
	}

}

