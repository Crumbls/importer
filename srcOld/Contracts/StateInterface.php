<?php

namespace Crumbls\Importer\Contracts;

interface StateInterface
{
	public function execute(): void;
	public function canTransition(): bool;
	public function getNextState(): ?string;
}
