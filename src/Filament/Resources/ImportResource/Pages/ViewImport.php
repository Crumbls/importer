<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Filament\Resources\ImportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;

class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Import Details')
                ->schema([
                    TextEntry::make('id')
                        ->label('Import ID'),

                    TextEntry::make('driver')
                        ->label('Driver')
                        ->formatStateUsing(fn (string $state): string => class_basename($state)),

                    TextEntry::make('source_type')
                        ->label('Source Type'),

                    TextEntry::make('state')
                        ->label('Current State')
                        ->formatStateUsing(fn (string $state): string => class_basename($state))
                        ->badge()
                        ->color(fn (string $state): string => match (true) {
                            str_contains($state, 'Completed') => 'success',
                            str_contains($state, 'Failed') || str_contains($state, 'Error') => 'danger',
                            str_contains($state, 'Processing') || str_contains($state, 'Executing') => 'warning',
                            default => 'gray',
                        }),

                    TextEntry::make('progress')
                        ->label('Progress')
                        ->suffix('%'),

                    TextEntry::make('data_limit')
                        ->label('Data Limit')
                        ->placeholder('No limit'),
                ])
                ->columns(2),

            Section::make('Source Configuration')
                ->schema([
                    TextEntry::make('source_detail')
                        ->label('Source Detail')
                        ->markdown(),
                ])
                ->collapsible(),

            Section::make('Filtering & Scoping')
                ->schema([
                    KeyValueEntry::make('scope_conditions')
                        ->label('Scope Conditions'),

                    TextEntry::make('where_clause')
                        ->label('Where Clause')
                        ->markdown(),
                ])
                ->collapsible()
                ->collapsed(),

            Section::make('Metadata & Results')
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->label('Metadata'),

                    KeyValueEntry::make('result')
                        ->label('Results'),

                    TextEntry::make('error_message')
                        ->label('Error Message')
                        ->color('danger')
                        ->visible(fn ($record): bool => !empty($record->error_message)),
                ])
                ->collapsible()
                ->collapsed(),

            Section::make('Timestamps')
                ->schema([
                    TextEntry::make('started_at')
                        ->label('Started At')
                        ->dateTime(),

                    TextEntry::make('completed_at')
                        ->label('Completed At')
                        ->dateTime(),

                    TextEntry::make('failed_at')
                        ->label('Failed At')
                        ->dateTime()
                        ->color('danger'),

                    TextEntry::make('created_at')
                        ->label('Created At')
                        ->dateTime(),

                    TextEntry::make('updated_at')
                        ->label('Updated At')
                        ->dateTime(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ]);
    }
}