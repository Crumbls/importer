<?php

namespace Crumbls\Importer\Events;

use Crumbls\Importer\Services\ImportService;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StorageServiceInitialized
{
    use Dispatchable, SerializesModels;

    public function __construct() {}
}