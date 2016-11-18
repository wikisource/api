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
use Stash\Pool;
use Stash\Driver\FileSystem;

$wsApi = new \Wikisource\Api\WikisourceApi();

// Cache.
$cache = new Pool( new FileSystem( [ 'path' => __DIR__.'/cache' ] ) );
$wsApi->setCache( $cache );

// Logging.
/*
$logger = new Logger( 'WikisourceApi' );
$logger->pushHandler( new StreamHandler( 'php://stdout', Logger::DEBUG ) );
$wsApi->setLogger( $logger );
/**/

$wikisource = $wsApi->fetchWikisource( 'en' );
$work = $wikisource->getWork( 'The Inn of Dreams' );

echo "\n'".$work->getWorkTitle()."'"
	 .' by '.join( ', ', $work->getAuthors() )
	 .' was published in '.$work->getYear()
	 .' by '.$work->getPublisher()
	 .' and is identified with '.$work->getWikidataItemNumber()."\n";

foreach ( $work->getIndexPages() as $indexPage ) {
	echo '* ' . $indexPage->getTitle() . "\n  - " . $indexPage->getImage() . "\n";
}
