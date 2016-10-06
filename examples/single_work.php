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

$wsApi = new \Wikisource\Api\WikisourceApi();

echo "Fetching English Wikisource . . . ";
$wikisource = $wsApi->fetchWikisource('en');
echo "done.\nFetching 'Pride and Prejudice' . . . ";
$work = $wikisource->getWork('Pride and Prejudice');
echo "done.\n".$work->getWorkTitle().' was published in '.$work->getYear()."\n";
