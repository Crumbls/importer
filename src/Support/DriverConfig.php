<?php

namespace Crumbls\Importer\Support;

use Crumbls\StateMachine\StateConfig;

class DriverConfig extends StateConfig
{
    protected array $steps = [];
    protected array $preferredTransitions = [];

    public function step(string $name, string $stepClass): static
    {
        $this->steps[$name] = $stepClass;
        return $this;
    }

    public function preferredTransition(string $from, string $to): static
    {
        $this->preferredTransitions[$from] = $to;
        return $this;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getStep(string $name): ?string
    {
        return $this->steps[$name] ?? null;
    }

    public function getPreferredTransitions(): array
    {
        return $this->preferredTransitions;
    }

    public function getPreferredTransition(string $from): ?string
    {
        return $this->preferredTransitions[$from] ?? null;
    }
}