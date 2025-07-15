<?php

namespace Crumbls\Importer\States\XmlDriver;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\Exceptions\ImportException;
use Exception;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Schema\Blueprint;

class ExtractState extends AbstractState
{

    public function onEnter(): void
    {
		// TODO: Implement XML extraction logic
		throw ImportException::extractionFailed('XML extraction not implemented');
    }

}