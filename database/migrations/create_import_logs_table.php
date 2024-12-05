<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function getTable() : string {
		$modelClass = \Crumbls\Importer\Models\ImportLog::class;
		return with(new $modelClass())->getTable();
	}
	public function up()
	{
		Schema::create($this->getTable(), function (Blueprint $table) {
			$table->id();
			$table->foreignId('import_state_id')->constrained()->cascadeOnDelete();
			$table->string('level');
			$table->string('message');
			$table->json('metadata')->nullable();
			$table->timestamps();
		});
	}

	public function down()
	{
		Schema::dropIfExists($this->getTable());
	}
};