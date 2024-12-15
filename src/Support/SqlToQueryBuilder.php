<?php

namespace Crumbls\Importer\Support;


use Illuminate\Support\Facades\DB;
use PHPSQLParser\PHPSQLParser;
use Illuminate\Database\Query\Builder;
use Exception;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Utils\Query;

class SqlToQueryBuilder
{
	protected $parser;
	protected $parsedSQL;
	protected $connection;

	/**
	 * TODO: Add in casting.
	 * @param $connection
	 */
	public function __construct($connection = null)
	{
		$this->parser = new PHPSQLParser();
		$this->connection = $connection ?? DB::connection();
	}

	/**
	 * Set database connection
	 */
	public function setConnection($connection): self
	{
		$this->connection = $connection;
		return $this;
	}

	/**
	 * Convert SQL query to Query Builder
	 */
	public function convert(string $sql): Builder
	{
		try {
			$parser = new Parser($sql);
			foreach($parser->statements as $statement) {
				$flags = Query::getFlags($statement);
				dd($statement, $flags);
			}

			$queryType = $flags->querytype;
			foreach($parser->statements as $statement) {
				dd($statement);
			}
			dd($parser);
			return match($queryType) {
//				'SELECT' => $this->handleSelect(),
				'INSERT' => $this->handleInsert(),
//				'UPDATE' => $this->handleUpdate(),
//				'DELETE' => $this->handleDelete(),
				default => throw new Exception('Unsupported SQL statement type'),
			};
			dd($parser->statements, $flags);
		} catch (\Throwable $e) {

		}
		try {
			$this->parsedSQL = $this->parser->parse($sql);

			// Determine the type of SQL statement
			$queryType = $this->determineQueryType();

			return match($queryType) {
				'SELECT' => $this->handleSelect(),
				'INSERT' => $this->handleInsert(),
				'UPDATE' => $this->handleUpdate(),
				'DELETE' => $this->handleDelete(),
				default => throw new Exception('Unsupported SQL statement type'),
			};
		} catch (Exception $e) {
			throw new Exception("Failed to convert SQL: {$e->getMessage()}");
		}
	}

	/**
	 * Handle SELECT statements
	 */
	protected function handleSelect(): Builder
	{
		$mainTable = $this->getMainTable();
		$query = $this->connection->table($mainTable);

		// Handle selected columns
		if (isset($this->parsedSQL['SELECT'])) {
			$columns = $this->parseSelectedColumns();
			$query->select($columns);
		}

		// Handle WHERE clauses
		if (isset($this->parsedSQL['WHERE'])) {
			$this->buildWhereConditions($query);
		}

		// Handle JOINs
		if (isset($this->parsedSQL['JOIN'])) {
			$this->buildJoinClauses($query);
		}

		// Handle ORDER BY
		if (isset($this->parsedSQL['ORDER'])) {
			$this->buildOrderBy($query);
		}

		// Handle GROUP BY
		if (isset($this->parsedSQL['GROUP'])) {
			$this->buildGroupBy($query);
		}

		// Handle LIMIT and OFFSET
		if (isset($this->parsedSQL['LIMIT'])) {
			$this->buildLimit($query);
		}

		return $query;
	}

	/**
	 * Handle INSERT statements
	 */
	protected function handleInsert(): Builder
	{
		$mainTable = $this->getMainTable();

		$query = $this->connection->table($mainTable);

		$values = $this->parseInsertValues();

		return $query->insert($values);
	}

	/**
	 * Handle UPDATE statements
	 */
	protected function handleUpdate(): Builder
	{
		$mainTable = $this->getMainTable();
		$query = $this->connection->table($mainTable);

		// Parse update values
		$values = $this->parseUpdateValues();

		// Handle WHERE clauses
		if (isset($this->parsedSQL['WHERE'])) {
			$this->buildWhereConditions($query);
		}

		return $query->update($values);
	}

	/**
	 * Handle DELETE statements
	 */
	protected function handleDelete(): Builder
	{
		$mainTable = $this->getMainTable();
		$query = $this->connection->table($mainTable);

		// Handle WHERE clauses
		if (isset($this->parsedSQL['WHERE'])) {
			$this->buildWhereConditions($query);
		}

		return $query->delete();
	}

	/**
	 * Build WHERE conditions
	 */
	protected function buildWhereConditions(Builder $query, array $conditions = null): void
	{
		$conditions = $conditions ?? $this->parsedSQL['WHERE'];

		foreach ($conditions as $condition) {
			if (isset($condition['expr_type'])) {
				switch ($condition['expr_type']) {
					case 'operator':
						continue 2;
					case 'colref':
						$column = $condition['base_expr'];
						break;
					case 'const':
						$value = trim($condition['base_expr'], "'\"");
						break;
					case 'in-list':
						$value = $this->parseInListValues($condition);
						break;
					case 'subquery':
						$value = $this->parseSubquery($condition);
						break;
				}
			}

			if (isset($column) && isset($value)) {
				$operator = $this->parseOperator($conditions);
				if ($operator === 'IN') {
					$query->whereIn($column, $value);
				} elseif ($operator === 'NOT IN') {
					$query->whereNotIn($column, $value);
				} else {
					$query->where($column, $operator, $value);
				}
				unset($column, $value);
			}
		}
	}

	/**
	 * Build JOIN clauses
	 */
	protected function buildJoinClauses(Builder $query): void
	{
		foreach ($this->parsedSQL['JOIN'] as $join) {
			$table = $join['table'];
			$conditions = $join['ref_clause'];
			$type = $this->parseJoinType($join['join_type']);

			$query->$type($table, function($join) use ($conditions) {
				foreach ($conditions as $condition) {
					$join->on(
						$condition['first']['base_expr'],
						$condition['operator'],
						$condition['second']['base_expr']
					);
				}
			});
		}
	}

	/**
	 * Build ORDER BY clauses
	 */
	protected function buildOrderBy(Builder $query): void
	{
		foreach ($this->parsedSQL['ORDER'] as $order) {
			$direction = isset($order['direction']) ? strtolower($order['direction']) : 'asc';
			$query->orderBy($order['base_expr'], $direction);
		}
	}

	/**
	 * Build GROUP BY clauses
	 */
	protected function buildGroupBy(Builder $query): void
	{
		$columns = array_map(function($group) {
			return $group['base_expr'];
		}, $this->parsedSQL['GROUP']);

		$query->groupBy($columns);
	}

	/**
	 * Build LIMIT and OFFSET
	 */
	protected function buildLimit(Builder $query): void
	{
		$limit = $this->parsedSQL['LIMIT'];

		if (isset($limit['offset'])) {
			$query->offset($limit['offset']);
		}

		if (isset($limit['rowcount'])) {
			$query->limit($limit['rowcount']);
		}
	}

	/**
	 * Get the main table name from the SQL
	 */
	protected function getMainTable(): string
	{
		if (isset($this->parsedSQL['INSERT'])) {
			return \Arr::first($this->parsedSQL['INSERT'], function($block) {
				return array_key_exists('table', $block);
			})['table'];
		}

		if (isset($this->parsedSQL['UPDATE'])) {
			return $this->parsedSQL['UPDATE'][0]['table'];
		}

		if (isset($this->parsedSQL['FROM'])) {
			return $this->parsedSQL['FROM'][0]['table'];
		}

		throw new Exception('Could not determine main table');
	}

	/**
	 * Determine the type of SQL query
	 */
	protected function determineQueryType(): string
	{
		$types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

		foreach ($types as $type) {
			if (isset($this->parsedSQL[$type])) {
				return $type;
			}
		}

		throw new Exception('Unknown query type');
	}

	/**
	 * Parse operator from WHERE conditions
	 */
	protected function parseOperator(array $conditions): string
	{
		$operator = '=';

		foreach ($conditions as $condition) {
			if ($condition['expr_type'] === 'operator') {
				$operator = $condition['base_expr'];
				break;
			}
		}

		return $this->normalizeOperator($operator);
	}

	/**
	 * Normalize SQL operators to Laravel operators
	 */
	protected function normalizeOperator(string $operator): string
	{
		return match(strtoupper($operator)) {
			'<>' => '!=',
			'NOT LIKE' => 'not like',
			'LIKE' => 'like',
			default => $operator,
		};
	}

	/**
	 * Parse JOIN type
	 */
	protected function parseJoinType(string $type): string
	{
		return match(strtoupper($type)) {
			'LEFT' => 'leftJoin',
			'RIGHT' => 'rightJoin',
			'INNER' => 'join',
			default => 'join',
		};
	}

	/**
	 * Parse selected columns
	 */
	protected function parseSelectedColumns(): array
	{
		$columns = [];

		foreach ($this->parsedSQL['SELECT'] as $column) {
			if ($column['expr_type'] === 'colref') {
				$columns[] = $column['base_expr'];
			}
		}

		return $columns;
	}

	/**
	 * Parse INSERT values
	 */
	protected function parseInsertValues(): array
	{
		$columns = [];
		$values = [];

		foreach ($this->parsedSQL['INSERT'] as $insert) {
			if (isset($insert['columns'])) {
				foreach ($insert['columns'] as $column) {
					$columns[] = $column['base_expr'];
				}
			}
		}

		foreach ($this->parsedSQL['VALUES'] as $value) {
			$values[] = trim($value['base_expr'], "'\"");
		}

		dd($values, $this->parsedSQL);

		dd($columns, $values);

		return array_combine($columns, $values);
	}

	/**
	 * Parse UPDATE values
	 */
	protected function parseUpdateValues(): array
	{
		$values = [];

		foreach ($this->parsedSQL['SET'] as $set) {
			$values[$set['column']] = trim($set['value'], "'\"");
		}

		return $values;
	}

	/**
	 * Parse IN list values
	 */
	protected function parseInListValues(array $condition): array
	{
		return array_map(function($item) {
			return trim($item['base_expr'], "'\"");
		}, $condition['sub_tree']);
	}

	/**
	 * Parse subquery
	 */
	protected function parseSubquery(array $condition): Builder
	{
		$subquery = $condition['sub_tree'];
		return $this->convert($subquery);
	}
}