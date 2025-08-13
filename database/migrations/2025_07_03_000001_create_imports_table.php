<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function getTableName() : string {
		// Use default table name during migrations to avoid dependency issues
		return 'imports';
	}

    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->id();
            $table->string('driver');
            $table->string('source_type'); // file, connection, api, database, etc.
            $table->text('source_detail'); // file path, connection string, API endpoint, etc.
            $table->string('state')->default('pending');
            $table->json('state_machine_data')->nullable(); // state machine persistence
            $table->integer('progress')->default(0); // 0-100
            $table->json('metadata')->nullable(); // driver-specific config and options
            $table->json('result')->nullable(); // final import results
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('batch_id')->nullable(); // for grouping related imports
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['driver', 'state']);
            $table->index(['user_id', 'state']);
            $table->index('batch_id');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
};