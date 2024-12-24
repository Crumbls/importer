<?php

namespace Crumbls\Importer\States;

class StateRegistry
{
	protected array $states = [];
	protected array $transitions = [];

	public function registerState(string $state, array $transitions): void
	{
		$this->states[$state] = $transitions;
	}

	public function getTransitionsFor(string $state): array
	{
		return $this->states[$state] ?? [];
	}
}