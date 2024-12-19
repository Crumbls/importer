# Laravel Importer

A flexible, state-based importer package for Laravel with support for CSV, SQL, WordPress XML, and Eloquent data sources.

## This is early beta and should not be used.  

Right now, it will take either a WordPress sql, XML, or connection and localize it to a sqlite database.  It then
generates models.

The next step is to handle migrations.  We will see if a table already exists, if it does, update what is necessary.  
Otherwise, create from scratch.  I have it working as a command, but need to integrate it into the package.

Then create Filament resource.

Then it's just moving data.

Move all of these so they run through a job process so it can be paused as necessary.

## Installation

```bash
composer require crumbls/importer
```

## Basic Usage

Seriously, don't use this yet.  I'm reworking a few things that I think will make substantial improvements and allow us
to do more than just WordPress.  

This is what I am running to pull random import xml files from WordPress for tests right now.  Syntax is similar for
WP connections or sql files ( not yet supported )  

Right now, I'm throwing hundreds of files I've pulled from the internet at it to test with. Taking a break for breakfast
and then I'll be back.
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