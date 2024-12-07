<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\Concerns\HasStateMachine;
use Crumbls\Importer\States\AbstractState;


class ParseState extends AbstractState
{
	use HasStateMachine;

	public function __construct()
	{
		echo __LINE__;
		return;
		$this->states = [

			ValidateState::class,
			DetectDelimiterState::class,
			ReadHeadersState::class,
			DefineModelState::class,
			ParseState::class
		];

//		$this->setCurrentState(ValidateState::class);

//		$this->setParameter('lines_per_iteration', 10);
	}

	public function execute(): void
	{
		echo __METHOD__.'<br />'.PHP_EOL;

		// Reading logic here
	}

	public function canTransition(): bool
	{
		echo __METHOD__.'<br />'.PHP_EOL;

		return true;
		return true; // Add reading completion check
	}

	public function getNextState(): ?string
	{
		return null;
	}

	/**
	 * Override Configuration
	 */
	public function configure(?array $config): self {
		throw new \Exception('Not available.');
		$this->config = array_merge(isset($this->config) ? $this->config : [], $config ? $config : []);
		return $this;
	}
	/** * Get the configuration value using dot notation. * * @param string $key * @param mixed $default * @return mixed */
	public function getParameter($key, $default = null) : mixed {
		return $this->getDriver()->getParameter($key, $default);
	}

	/** * Get the configuration value using dot notation. * * @param string $key * @param mixed $default * @return mixed */
	public function setParameter($key, $value = null) : self {
		return $this->getDriver()->setParameter($key, $value);
	}

	public function getAllParameters() : array {
		return $this->getDriver()->getAllParameters();
	}
}