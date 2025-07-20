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
use Crumbls\Importer\States\ExtractState as BaseState;

class ExtractState extends BaseState
{

    public function onEnter(): void
    {
//		dd(__LINE__);
        // Base implementation - override in specific drivers
        // This allows the WpXmlDriver to extend without throwing exceptions
    }

	public function execute() : bool {
		dd(__LINE__);
	}

	public function onExit(): void {
	}

}