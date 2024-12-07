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
		$modelClass = \Crumbls\Importer\Models\Import::class;
		return with(new $modelClass())->getTable();
	}
	public function up()
	{
		Schema::create(static::getTable(), function (Blueprint $table) {
			$table->id();
			$table->string('driver')->nullable();
			$table->string('source')->nullable();
			$table->string('state')->nullable();
//			$table->string('status');
			$table->json('config')->nullable();
			$table->json('metadata')->nullable();
			$table->timestamp('started_at')->nullable();
			$table->timestamp('completed_at')->nullable();
			$table->timestamps();
//			$table->softDeletes();
		});
	}

	public function down()
	{
		Schema::dropIfExists(static::getTable());
	}
};