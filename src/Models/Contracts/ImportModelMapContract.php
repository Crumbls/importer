<?php

namespace Crumbls\Importer\Models\Contracts;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\StateMachine\StateMachine;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface ImportModelMapContract
{
    public function import(): BelongsTo;

}