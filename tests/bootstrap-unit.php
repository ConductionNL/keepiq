<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
// Only attempt to load base.php if a valid NC config exists (with a DB).
$ncBasePath = __DIR__ . '/../../../lib/base.php';
$ncConfigPath = __DIR__ . '/../../../config/config.php';
$ncLoaded = false;

if (file_exists($ncBasePath) && file_exists($ncConfigPath)) {
	$ncConfig = [];
	include $ncConfigPath;
	$ncConfig = $CONFIG ?? [];
	if (isset($ncConfig['dbtype']) || isset($ncConfig['dbhost'])) {
		require_once $ncBasePath;
		$ncLoaded = true;
	}
}

// If Nextcloud could not be loaded, register OCP stubs for pure unit tests.
if ($ncLoaded === false && $autoloader instanceof \Composer\Autoload\ClassLoader) {
	$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
	$loader = new \Composer\Autoload\ClassLoader();
	$loader->addPsr4('Test\\', $serverTestsLib);
	$loader->register(true);
}

// Stub Doctrine\DBAL\ParameterType for unit tests that mock IDBConnection or
// IQueryBuilder. The real class lives in doctrine/dbal which is provided by
// Nextcloud at runtime but not part of keepiq's composer dev deps.
if (class_exists('Doctrine\\DBAL\\ParameterType') === false) {
	eval(
		'namespace Doctrine\\DBAL; '
		. 'enum ParameterType: int { '
		. 'case NULL = 0; '
		. 'case INTEGER = 1; '
		. 'case STRING = 2; '
		. 'case LARGE_OBJECT = 3; '
		. 'case BOOLEAN = 5; '
		. 'case BINARY = 6; '
		. 'case ASCII = 7; '
		. '}'
	);
}
