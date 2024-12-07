<?php

namespace Crumbls\Importer\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

trait HasConfiguration {
	private array $config;

	public function configure(?array $config): self {
		$this->config = array_merge(isset($this->config) ? $this->config : [], $config ? $config : []);
		return $this;
	}
	/** * Get the configuration value using dot notation. * * @param string $key * @param mixed $default * @return mixed */
	public function getParameter($key, $default = null) : mixed {
		if (!isset($this->config)) {
			$this->config = [];
			$this->setParameter($key, $default);
			return $default;
		}
		return Arr::get($this->config, $key, $default);
	}

	/** * Get the configuration value using dot notation. * * @param string $key * @param mixed $default * @return mixed */
	public function setParameter($key, $value = null) : self {
		if (!isset($this->config)) {
			$this->config = [];
		}
		Arr::set(
			$this->config,
			$key,
			$value
		);
		return $this;
	}

	public function getAllParameters() : array {
		if (!isset($this->config)) {
			$this->config = [];
		}
		return $this->config;
	}

}