<?php

declare(strict_types=1);
$loader = require getenv('DUCKDB_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$loader->addPsr4('Survos\\DuckDbBundle\\', dirname(__DIR__).'/src');
require dirname(__DIR__).'/demo/app.php';
