<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasConnection {

	public function getConnection() : string {
		return $this->config('connection');
	}

	public function connection(string $connection) : static {
		$this->configure('connection', $connection);
		return $this;
	}
}