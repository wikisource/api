<?php
/**
 * This example lists the names and languages of all Wikisources
 *
 * It demonstrates the caching facility.
 *
 * @package WikisourceApi
 */

/**
 * Composer autoloading
 */
require __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Stash\Driver\FileSystem;
use Stash\Pool;

$wsApi = new \Wikisource\Api\WikisourceApi();

// Cache.
$cache = new Pool(new FileSystem(['path' => __DIR__.'/cache']));
$wsApi->setCache($cache);

// Logging.
$logger = new Logger('WikisourceApi');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$wsApi->setLogger($logger);
Eloquent\Asplode\Asplode::install();

// The actual example.
$wikisources = $wsApi->fetchWikisources();
echo count($wikisources)." Wikisources found:\n";
foreach ($wikisources as $ws) {
    echo "* ".$ws->getLanguageCode()." - ".$ws->getLanguageName()."\n";
}
