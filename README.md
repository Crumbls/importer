# Laravel Importer

A flexible, state-based importer package for Laravel with support for CSV, SQL, WordPress XML, and Eloquent data sources.

## This is early beta and should not be used.  

Right now, it will take either a WordPress sql, XML, or connection and localize it to a sqlite database.  It then
generates models, migrations, and then Filament resources.

It stores the data in a local, temporary, SQLite database to help handle large files or migrations down the road.

Right now, I am trying to figure out the best way to move data from that to the live server eloquently.  I'm doing some testing,
but would love any input.

After this, I'll build an official Filament package to manage this for the non-command line folk.

## Installation

```bash
composer require crumbls/importer
```

## Basic Usage

Seriously, don't use this yet.  I'm reworking a few things that I think will make substantial improvements and allow us
to do more than just WordPress.  

This is what I am running to pull random import xml files from WordPress for tests right now.  Syntax is similar for
WP connections ( not yet supported ) or sql files.   

Right now, I'm throwing hundreds of files I've pulled from the internet at it to test with. It works properly with sql or xml.

```
	$file = \Arr::random(glob(base_path('import-tests').'/*.xml'));
	$import = \Crumbls\Importer\Models\Import::firstOrCreate([
		'driver' => 'wordpress-xml',
		'source' => $file,
		'state' => \Crumbls\Importer\Drivers\WordPressXML\States\ValidateState::class
	]);

	$driver = $import->getDriver();

	while ($driver->canAdvance()) {
		$driver->advance();
	}

```