<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Get our table name.
	 * @return string
	 */
	public function getTable() : string {
		$modelClass = \Crumbls\Importer\Models\ImportState::class;
		return with(new $modelClass())->getTable();
	}
	public function up()
	{
		Schema::create(static::getTable(), function (Blueprint $table) {
			$table->id();
			$table->string('source_type');
			$table->string('status');
			$table->json('metadata')->nullable();
			$table->json('cursor')->nullable();
			$table->timestamp('started_at')->nullable();
			$table->timestamp('completed_at')->nullable();
			$table->timestamps();
		});
	}

	public function down()
	{
		Schema::dropIfExists(static::getTable());
	}
};