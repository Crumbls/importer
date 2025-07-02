<?php

declare(strict_types=1);

namespace Crumbls\Importer\Types;

/**
 * Type definitions for PHPStan Level 4 compliance
 * 
 * These types ensure strict typing throughout the Extended ETL Pipeline
 */

/**
 * @phpstan-type FieldDefinition array{
 *     name: string,
 *     type: string,
 *     php_type: string,
 *     nullable: bool,
 *     length: int|null,
 *     unique: bool,
 *     index: bool,
 *     confidence: float
 * }
 */

/**
 * @phpstan-type RelationshipDefinition array{
 *     type: string,
 *     related_model: string,
 *     foreign_key: string,
 *     method_name: string
 * }
 */

/**
 * @phpstan-type IndexDefinition array{
 *     field: string,
 *     type: 'unique'|'index',
 *     reason: string
 * }
 */

/**
 * @phpstan-type ValidationRules array<string, list<string>>
 */

/**
 * @phpstan-type TypeCasts array<string, string>
 */

/**
 * @phpstan-type SchemaAnalysis array{
 *     source_type: string,
 *     table_name: string,
 *     model_name: string,
 *     fields: list<FieldDefinition>,
 *     relationships: list<RelationshipDefinition>,
 *     indexes: list<IndexDefinition>,
 *     fillable: list<string>,
 *     casts: TypeCasts,
 *     validation_rules: ValidationRules,
 *     metadata: array{
 *         total_records: int,
 *         sample_size: int,
 *         analysis_confidence: float,
 *         detected_patterns: array<string, list<string>>
 *     },
 *     requires_model?: bool,
 *     requires_migration?: bool
 * }
 */

/**
 * @phpstan-type GenerationResult array{
 *     created: bool,
 *     reason?: string,
 *     model_name?: string,
 *     model_path?: string,
 *     table_name?: string,
 *     migration_path?: string,
 *     migration_name?: string,
 *     factory_name?: string,
 *     factory_path?: string,
 *     fillable_count?: int,
 *     casts_count?: int,
 *     relationships_count?: int,
 *     fields_count?: int,
 *     indexes_count?: int
 * }
 */

/**
 * @phpstan-type ExtendedPipelineConfig array{
 *     core_steps: array<string, bool>,
 *     laravel_steps: array<string, bool>,
 *     laravel_options: array{
 *         model_namespace: string,
 *         model_directory: string,
 *         migration_directory: string,
 *         factory_directory: string,
 *         seeder_directory: string,
 *         filament_directory: string,
 *         model_options: array<string, mixed>,
 *         migration_options: array<string, mixed>,
 *         factory_options: array<string, mixed>,
 *         filament_options: array<string, mixed>
 *     },
 *     analysis_options: array{
 *         sample_size: int,
 *         detect_relationships: bool,
 *         suggest_indexes: bool,
 *         detect_enums: bool,
 *         analyze_patterns: bool
 *     },
 *     safety_options: array{
 *         backup_existing_files: bool,
 *         dry_run_mode: bool,
 *         confirm_before_overwrite: bool,
 *         skip_if_exists: bool
 *     },
 *     table_name?: string,
 *     model_name?: string
 * }
 */

/**
 * @phpstan-type FieldAnalysis array{
 *     samples: list<string>,
 *     null_count: int,
 *     empty_count: int,
 *     max_length: int,
 *     min_length: int,
 *     total_length: int,
 *     detected_types: array<string, int>,
 *     pattern_matches: array<string, int>,
 *     unique_values: list<string>,
 *     value_frequency: array<string, int>,
 *     is_unique: bool,
 *     seen_values: list<string>
 * }
 */

/**
 * @phpstan-type DataPattern array{
 *     samples: list<string>,
 *     unique_values: list<string>,
 *     common_values: list<string>,
 *     pattern_type: string,
 *     length_range: array{
 *         min: int,
 *         max: int,
 *         avg: int
 *     }
 * }
 */

/**
 * @phpstan-type TypeDetector callable(string): bool
 */

/**
 * @phpstan-type PatternAnalyzer callable(string): bool
 */

/**
 * @phpstan-type ChunkCallback callable(list<array<string, mixed>>): mixed
 */

/**
 * @phpstan-type TransformerCallback callable(array<string, mixed>): array<string, mixed>|null
 */

/**
 * @phpstan-type ExportOptions array{
 *     headers?: bool|list<string>,
 *     column_mapping?: array<string, string>,
 *     chunk_size?: int,
 *     transformer?: TransformerCallback
 * }
 */

class SchemaTypes
{
    // This class exists only to hold PHPStan type definitions
    // No implementation needed - just for documentation and IDE support
}