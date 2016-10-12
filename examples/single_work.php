<?php
/**
 * This example retrieves a single Work from a Wikisource
 *
 * It displays some basic information about the Work.
 *
 * @package WikisourceApi
 */

/**
 * Composer autoloading
 */
require __DIR__.'/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$wsApi = new \Wikisource\Api\WikisourceApi();

// Logging.
$logger = new Logger('WikisourceApi');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$wsApi->setLogger($logger);

echo "Fetching English Wikisource . . . ";
$wikisource = $wsApi->fetchWikisource('en');
echo "done.\nFetching 'Pride and Prejudice' . . . ";
$work = $wikisource->getWork('Pride and Prejudice');
echo "done.\n".$work->getWorkTitle()
     .' was published in '.$work->getYear()
     .' and is identified by '.$work->getWikidataItemNumber()."\n";
