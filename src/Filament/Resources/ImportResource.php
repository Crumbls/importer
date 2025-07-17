<?php

namespace Crumbls\Importer\Filament\Resources;

use Crumbls\Importer\Filament\Resources\ImportResource\Pages;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImportResource extends Resource
{
    protected static ?int $navigationSort = 10;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-arrow-down-tray';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('importer::navigation.group');
    }

    public static function getModel(): string
    {
        return ModelResolver::import();
    }

    public static function getModelLabel(): string
    {
        return 'Import';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Imports';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Import Details')
                    ->schema([
                        TextInput::make('driver')
                            ->label('Import Driver')
                            ->default(config('importer.default', 'csv'))
                            ->required()
                            ->maxLength(255),
                        
                        Select::make('source_type')
                            ->label('Source Type')
                            ->options([
                                'file' => 'File',
                                'url' => 'URL',
                                'database' => 'Database',
                                'api' => 'API',
                            ])
                            ->required(),
                        
                        Textarea::make('source_detail')
                            ->label('Source Details')
                            ->required()
                            ->columnSpanFull()
                            ->rows(3)
                            ->helperText('Provide the file path, URL, or other source details'),
                        
                        KeyValue::make('metadata')
                            ->label('Additional Metadata')
                            ->columnSpanFull()
                            ->reorderable()
                            ->addActionLabel('Add metadata field'),
                    ])
                    ->columns(2),
                
                Section::make('Processing Options')
                    ->schema([
                        TextInput::make('batch_id')
                            ->label('Batch ID')
                            ->maxLength(255)
                            ->helperText('Optional batch identifier for grouping imports'),
                        
                        TextInput::make('progress')
                            ->label('Progress')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('source_type')
                    ->label('Source Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'file' => 'success',
                        'url' => 'info',
                        'database' => 'warning',
                        'api' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'Pending') => 'warning',
                        str_contains($state, 'Processing') => 'info',
                        str_contains($state, 'Completed') => 'success',
                        str_contains($state, 'Failed') => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('progress')
                    ->label('Progress')
                    ->suffix('%')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable()
                    ->placeholder('System'),
                
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not started'),
                
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not completed'),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver')
                    ->label('Driver')
                    ->options([
                        'csv' => 'CSV',
                        'excel' => 'Excel',
                        'json' => 'JSON',
                        'xml' => 'XML',
                        'database' => 'Database',
                    ]),
                
                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Source Type')
                    ->options([
                        'file' => 'File',
                        'url' => 'URL',
                        'database' => 'Database',
                        'api' => 'API',
                    ]),
                
                Tables\Filters\Filter::make('failed')
                    ->label('Failed Imports')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('failed_at')),
                
                Tables\Filters\Filter::make('completed')
                    ->label('Completed Imports')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('completed_at')),
            ])
            ->actions([
//                Tables\Actions\EditAction::make(),
  //              Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
				BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImports::route('/'),
            'create' => Pages\CreateImport::route('/create'),
	        'continue' => Pages\ImportStep::route('/{record}/continue'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user']);
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ImportContract;
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof ImportContract;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ImportContract;
    }
}