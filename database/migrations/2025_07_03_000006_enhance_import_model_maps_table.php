<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getTableName(): string 
    {
        // Use default table name during migrations to avoid dependency issues
        return 'import_model_maps';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table($this->getTableName(), function (Blueprint $table) {
            // Add entity_type field for driver-agnostic identification
            $table->string('entity_type')->after('source_type')->nullable()->index();
            
            // Add comprehensive JSON fields for ImportModelMap v2.0 structure
            $table->json('source_info')->after('metadata')->default('{}');
            $table->json('destination_info')->after('source_info')->default('{}');
            $table->json('schema_mapping')->after('destination_info')->default('{}');
            $table->json('relationships')->after('schema_mapping')->default('{}');
            $table->json('conflict_resolution')->after('relationships')->default('{}');
            $table->json('data_validation')->after('conflict_resolution')->default('{}');
            $table->json('model_metadata')->after('data_validation')->default('{}');
            $table->json('performance_config')->after('model_metadata')->default('{}');
            $table->json('migration_metadata')->after('performance_config')->default('{}');
            
            // Add indexes for new fields
            $table->index(['import_id', 'entity_type']);\
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table($this->getTableName(), function (Blueprint $table) {
            // Remove indexes first
            $table->dropIndex(['import_id', 'entity_type']);

            // Remove added columns
            $table->dropColumn([
                'entity_type',
                'source_info',
                'destination_info', 
                'schema_mapping',
                'relationships',
                'conflict_resolution',
                'data_validation',
                'model_metadata',
                'performance_config',
                'migration_metadata'
            ]);
        });
    }
};