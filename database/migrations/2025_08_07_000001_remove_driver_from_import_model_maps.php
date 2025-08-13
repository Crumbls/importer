<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getTableName(): string 
    {
        return 'import_model_maps';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {


		$tableName = $this->getTableName();


		$columnName = 'driver';
	    if (Schema::hasColumn($tableName, $columnName)) //check the column
	    {
            Schema::table($tableName, function (Blueprint $table) use ($columnName, $tableName)
            {
				/*
	            $relationships = \Illuminate\Support\Facades\DB::select('PRAGMA index_list("'.$tableName.'");');
	            foreach($relationships as $r) {
					if (!strpos($r->name, $columnName)) {
						continue;
					}
		            try {
						\Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS '.$r->name);
//						$table->dropIndex($r->name);
					} catch (\Throwable $e) {}
	            }

	            dump("{$tableName}_{$columnName}_index");
*/
	            $table->dropColumn($columnName); //drop it
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
