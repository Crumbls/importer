<?php

namespace Crumbls\Importer\Drivers\Contracts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\PendingRequest;

interface DriverContract
{
    public static function canHandle(ImportContract $record): bool;

	public static function fromModel(ImportContract $record) : static;

    public static function getPriority(): int;

}