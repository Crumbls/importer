<?php

namespace Crumbls\Importer\States\Concerns;

use Crumbls\Importer\Support\SourceResolverManager;

trait HasSourceResolver
{
    public function getSourceResolver(): SourceResolverManager
    {
        $record = $this->getRecord();
        return $record->getSourceResolver();
    }
}