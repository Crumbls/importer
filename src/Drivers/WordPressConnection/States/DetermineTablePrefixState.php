<?php

namespace Crumbls\Importer\Drivers\WordPressConnection\States;

use Crumbls\Importer\States\AbstractState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class DetermineTablePrefixState extends AbstractState {
	public function getName(): string {
		return 'determine-table-prefix';
	}

	public function handle(): void {
		$connection = $this->getDriver()->getImportConnection();
		$tables = $this->getAllTables($connection);

		if (empty($tables)) {
			throw new \Exception("No tables found in database");
		}

		// Find common prefix
		$prefix = $this->findCommonPrefix($tables);

		// Save prefix to metadata
		$record = $this->getRecord();
		$md = $record->metadata ?? [];
		$md['table_prefix'] = $prefix;

		$record->update([
			'metadata' => $md
		]);

		/**
		 * Great, we know the prefix now!
		 */
		$connection->setTablePrefix($prefix);

	}

	protected function getAllTables($connection): array {
		$driver = $connection->getDriverName();

		return match($driver) {
			'sqlite' => $this->getSqliteTables($connection),
			'mysql' => $this->getMySqlTables($connection),
			'pgsql' => $this->getPostgresTables($connection),
			'sqlsrv' => $this->getSqlServerTables($connection),
			default => throw new \Exception("Unsupported database driver: {$driver}")
		};
	}

	protected function getSqliteTables($connection): array {
		return $connection->getPdo()
			->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
			->fetchAll(\PDO::FETCH_COLUMN);
	}

	protected function getMySqlTables($connection): array {
		$database = $connection->getDatabaseName();
		return $connection->getPdo()
			->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$database}'")
			->fetchAll(\PDO::FETCH_COLUMN);
	}

	protected function getPostgresTables($connection): array {
		return $connection->getPdo()
			->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname != 'pg_catalog' AND schemaname != 'information_schema'")
			->fetchAll(\PDO::FETCH_COLUMN);
	}

	protected function getSqlServerTables($connection): array {
		return $connection->getPdo()
			->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'")
			->fetchAll(\PDO::FETCH_COLUMN);
	}

	/**
	 * This method could use a lot of clean up.
	 * @param array $tables
	 * @return string|null
	 */
	protected function findCommonPrefix(array $tables): ?string {
		if (empty($tables)) {
			return null;
		}

		/**
		 * Just a default catch.
		 */
		$suffixes = [
			'commentmeta',
			'links',
			'postmeta',
			'posts',
			'term_relationships',
			'term_taxonomy',
			'termmeta',
			'usermeta',
			'users',
		];

		$filtered = array_filter($tables, function($table) use ($suffixes) {
			foreach ($suffixes as $suffix) {
				if (str_ends_with($table, $suffix)) {
					return true;
				}
			}
			return false;
		});

		if (count($filtered) == count($suffixes)) {
			$prefixes = array_map(function($table) use ($suffixes) {
				foreach ($suffixes as $suffix) {
					if (str_ends_with($table, $suffix)) {
						return substr($table, 0, -strlen($suffix));
					}
				}
			}, $filtered);
			$uniquePrefixes = array_unique($prefixes);
			if (count($uniquePrefixes) === 1) {
				return array_values($uniquePrefixes)[0];
			}
		}

		$firstTable = $tables[0];

		// Get the position of the first underscore
		$underscorePos = strpos($firstTable, '_');
		if ($underscorePos === false) {
			return null;
		}

		$potentialPrefix = substr($firstTable, 0, $underscorePos + 1);

		// Verify this prefix exists in all tables
		foreach ($tables as $table) {
			if (!str_starts_with($table, $potentialPrefix)) {
				return null;
			}
		}

		return $potentialPrefix;
	}
}