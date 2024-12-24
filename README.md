# Laravel Importer

A flexible, state-based importer package for Laravel with support for CSV, SQL, WordPress XML, and Eloquent data sources.

First of all, I don't know if there is any audience that needs this.  So if there isn't, I'll just nuke the thing.  If it
looks like anyone might show some interest, I am happy to develop it out more.

## This is early beta and should not be used.

It will take either a WordPress sql, XML, or connection and localize it to a sqlite database.  It then
generates models, migrations, and Filament resources.

Most drivers will localize the data into a temporary SQLite database to standardize the content.

We are officially migrating data from the temporary database to the production one now, but need to work on data
validation and sanitization in the process while still being aware of resource use.

## Installation

```bash
composer require crumbls/importer
```

## Basic Usage

Seriously, don't use this yet.  I'm reworking a few things that I think will make substantial improvements and allow us
to do more than just WordPress.  

This is what I am running to pull random import xml / sql files from WordPress for tests right now.  Syntax is similar for
WP connections ( not yet supported )   

Right now, I'm throwing hundreds of files I've pulled from the internet at it to test with. It works properly with sql or xml.
Please, do not do this.  I just want a way to test one of theexports I have to try and create errors with real data.

```
	Import::each(function($record) {
			$record->delete();
		});
		foreach(glob(database_path().'/wp_import_*.sqlite') as $test) {
			@unlink($test);
		}

		$file = \Arr::random(glob(base_path('import-tests').'/wordpres*.*'));

		$ext = substr($file, strrpos($file, '.') + 1);

		$driverMap = [
			'xml' => 'wordpress-xml',
			'sql' => 'wordpress-sql'
		];

		$driver = $driverMap[$ext];

		$import = Import::firstOrCreate([
			'driver' => $driver,
			'source' => $file,
		]);

		$driver = $import->getDriver();

		while ($driver->canAdvance()) {
			$driver->advance();
		}

```