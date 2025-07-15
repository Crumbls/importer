<?php

namespace Crumbls\Importer\StorageDrivers;

use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Illuminate\Support\Str;

abstract class AbstractDriver implements StorageDriverContract
{
	protected string $storePath;
	protected bool $connected = false;

	public function getStorePath(): string 
	{
		if (!isset($this->storePath)) {
			throw new \RuntimeException('Store path not set');
		}
		return $this->storePath;
	}

	public function deleteStore(): static
	{
		return $this;
	}

	public function configureFromMetadata(array $config): static 
	{
		$config = array_intersect_key($config, array_flip(preg_grep('#^storage\_#', array_keys($config))));
		unset($config['storage_driver']);
		foreach($config as $k => $v) {
			$method = Str::camel(substr($k, 8));
			if (method_exists($this, $method)) {
				$this->$method($v);
			}
		}
		return $this;
	}

	public function isConnected(): bool 
	{
		return $this->connected;
	}

	// Helper method for building WHERE conditions
	protected function buildConditions(array $conditions): array 
	{
		$where = [];
		$bindings = [];
		
		foreach ($conditions as $column => $value) {
			if (is_array($value)) {
				$placeholders = str_repeat('?,', count($value) - 1) . '?';
				$where[] = "{$column} IN ({$placeholders})";
				$bindings = array_merge($bindings, $value);
			} else {
				$where[] = "{$column} = ?";
				$bindings[] = $value;
			}
		}
		
		return [$where, $bindings];
	}
}