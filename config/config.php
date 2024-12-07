<?php


return [
	/*
	|--------------------------------------------------------------------------
	| Default Import Driver
	|--------------------------------------------------------------------------
	|
	| This option controls the default import driver that will be used when
	| importing data. By default, we'll use the WordPress XML driver, but you may
	| specify any of the other wonderful drivers provided here.
	|
	*/

	'default' => env('IMPORTER_DRIVER', 'wordpress-xml'),

	/*
	|--------------------------------------------------------------------------
	| Import Drivers
	|--------------------------------------------------------------------------
	|
	| Here you may configure all of the import drivers for your application.
	| Supported drivers: "wordpress-xml", "csv", "sql"
	|
	*/

	'drivers' => [
		'wordpress-xml' => [
		],

		'csv' => [
			'delimiter' => ',',
			'enclosure' => '"',
			'escape' => '\\',
			'batch_size' => 1000,
		],

		'sql' => [
			'batch_size' => 500,
		],
	],
];