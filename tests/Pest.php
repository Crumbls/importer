<?php

declare(strict_types=1);

uses(Crumbls\Importer\Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Importer Testing Helpers
|--------------------------------------------------------------------------
|
| Load the Pest helpers for the Crumbls Importer package. These provide
| fluent expectations, helper functions, and datasets for testing imports.
|
*/

// Autoload the testing helpers
require_once __DIR__ . '/../src/Testing/ImporterTestCase.php';
require_once __DIR__ . '/../src/Testing/TestFixtures.php';
require_once __DIR__ . '/../src/Testing/MockDriver.php';
require_once __DIR__ . '/../src/Testing/AssertionHelpers.php';
require_once __DIR__ . '/../src/Testing/PestHelpers.php';
