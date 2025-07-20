<?php

namespace Crumbls\Importer\Drivers\Contracts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Support\DriverConfig;
use Crumbls\StateMachine\StateConfig;

interface DriverContract
{
    public static function canHandle(ImportContract $record): bool;

    public static function fromModel(ImportContract $record): static;

    public static function getPriority(): int;

    public static function config(): DriverConfig|StateConfig;
}