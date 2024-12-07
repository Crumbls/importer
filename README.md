# Laravel Importer

A flexible, state-based importer package for Laravel with support for CSV, SQL, WordPress XML, and Eloquent data sources.

## This is early beta and should not be used.  

It is being rewritten today 12-08-2024.

Forks appreciated, input valued.

1) Verify input
2) Standardize input 
3) Make an educated guess on the output
4) Get approval for output
5) Process

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