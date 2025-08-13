<?php

namespace Crumbls\Importer\Filament\Resources\Imports;

use Crumbls\Importer\Filament\Resources\Imports\Pages\CreateImporter;
use Crumbls\Importer\Filament\Resources\Imports\Pages\EditImporter;
use Crumbls\Importer\Filament\Resources\Imports\Pages\ListImporters;
use Crumbls\Importer\Filament\Resources\Imports\Pages\ViewImporter;
use Crumbls\Importer\Filament\Resources\Imports\Schemas\ImporterForm;
use Crumbls\Importer\Filament\Resources\Imports\Tables\ImportersTable;
use App\Models\Importer;
use BackedEnum;
use Crumbls\Importer\Resolvers\ModelResolver;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ImportResource extends Resource
{

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

	public static function getModel(): string
	{
		return ModelResolver::import();
	}


	public static function form(Schema $schema): Schema
    {
        return ImporterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImporters::route('/'),
            'create' => CreateImporter::route('/create'),
            'view' => ViewImporter::route('/{record}'),
            'edit' => EditImporter::route('/{record}/edit'),
        ];
    }
}
