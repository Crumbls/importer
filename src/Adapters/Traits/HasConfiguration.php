<?php

namespace Crumbls\Importer\Adapters\Traits;

trait HasConfiguration {

	protected array $config;

	public function getConfig(): array {
		return $this->config;
	}

	public function setConfig(array $config): self {
		$this->config = array_merge($this->config, $config);
		return $this;
	}
}