<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Crumbls\Importer\Resolvers\ModelResolver;

return new class extends Migration
{

	public function getTableName() : string {
		$modelClass = ModelResolver::importModelMap();
		return with(new $modelClass())->getTable();
	}
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->id();

			$modelImport = ModelResolver::import();
			$tableForeign = with(new $modelImport)->getTable();
            
            // Relationship to import
            $table->foreignId('import_id')->constrained($tableForeign)->onDelete('cascade');
            
            // Source data information
            $table->string('source_table')->index(); // posts, postmeta, etc.
            $table->string('source_type')->nullable(); // post_type, meta_key, etc.
            
            // Target model information
            $table->string('target_model')->nullable();
			$table->string('target_table')->nullable(); // blog_posts (auto-derived from model)
            
            // Mapping configuration
            $table->json('field_mappings')->default('{}'); // {source_field: target_field}
            $table->json('transformation_rules')->default('{}'); // {field: {type: cast, options: {}}}
            
            // Driver and control
            $table->string('driver'); // WpXmlDriver, CsvDriver, etc.
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(100); // Processing order
            
            // Additional metadata (driver-specific)
            $table->json('metadata')->default('{}');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for common queries
            $table->index(['import_id', 'driver']);
            $table->index(['import_id', 'source_table']);
            $table->index(['import_id', 'is_active']);
            $table->index(['driver', 'source_table']);
            $table->index(['priority', 'is_active']);
            
            // Unique constraint to prevent duplicate mappings
            $table->unique(['import_id', 'source_table', 'source_type', 'target_model'], 'unique_mapping');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
};