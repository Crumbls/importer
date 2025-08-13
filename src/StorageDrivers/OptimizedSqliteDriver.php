<?php

namespace ChaseMiller\Importer\Drivers\Concerns;

use Illuminate\Database\SQLiteConnection;

class OptimizedSqliteDriver extends SqliteDriver
{
	private static array $connectionPool = [];
	private static int $maxConnections = 5;

	protected function getConnection(): SQLiteConnection
	{
		$connectionKey = md5($this->storePath);

		if (!isset(self::$connectionPool[$connectionKey])) {
			if (count(self::$connectionPool) >= self::$maxConnections) {
				// Remove oldest connection
				array_shift(self::$connectionPool);
			}

			self::$connectionPool[$connectionKey] = $this->createConnection();
		}

		return self::$connectionPool[$connectionKey];
	}
}