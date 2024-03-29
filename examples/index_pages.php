<?php
/**
 * This example retrieves a single Index page from a Wikisource and displays some basic information
 * about it
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
$cache = new Pool( new FileSystem( [ 'path' => __DIR__ . '/cache' ] ) );
$wsApi->setCache( $cache );

// Logging.
$logger = new Logger( 'WikisourceApi' );
$logger->pushHandler( new StreamHandler( 'php://stdout', Logger::DEBUG ) );
$wsApi->setLogger( $logger );

// Get the IndexPage from English Wikisource.
$enWs = $wsApi->newWikisourceFromUrl( "https://en.wikisource.org/wiki/Any_page" );
$prideAndPrejudiceIndex = $enWs->getIndexPageFromUrl(
	"https://en.wikisource.org/wiki/Index:Austen_-_Pride_and_Prejudice,_third_edition,_1817.djvu"
);
$pagesCount = count( $prideAndPrejudiceIndex->getPageList() );
$existing = count( $prideAndPrejudiceIndex->getPageList( true ) );

// Output summary.
echo $prideAndPrejudiceIndex->getTitle()
	. " (on " . $enWs->getDomainName() . ", " . $enWs->getWikidataId() . ") "
	. "has $pagesCount pages ($existing of which exist) "
	. "and is of quality '" . $prideAndPrejudiceIndex->getQuality() . "'.\n";
