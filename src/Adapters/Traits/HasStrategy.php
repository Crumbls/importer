<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasStrategy {

	public function getStrategy() : string {
		return $this->config('strategy');
	}

	public function strategy(string $strategy) : static {
		$this->configure('strategy', $strategy);
		return $this;
	}
}