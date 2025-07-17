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
        // Base implementation - override in specific drivers
        // This allows the WpXmlDriver to extend without throwing exceptions
    }

    // Filament UI Implementation - base version
    public function getFilamentTitle(ImportContract $record): string
    {
        return 'Processing XML Data';
    }

    public function getFilamentHeading(ImportContract $record): string
    {
        return 'Extracting XML Content';
    }

    public function getFilamentSubheading(ImportContract $record): ?string
    {
        return 'Processing your XML file and extracting content...';
    }

    public function hasFilamentForm(): bool
    {
        return false; // Base implementation has no form
    }

}