<?php
/**
 * This example retrieves a single Work from a Wikisource
 *
 * It displays some basic information about the Work.
 *
 * @package WikisourceApi
 */

require __DIR__ . '/../vendor/autoload.php';

$wsApi = new \Wikisource\Api\WikisourceApi();

// Cache.
$cache = new Stash\Pool( new Stash\Driver\FileSystem( [ 'path' => __DIR__ . '/cache' ] ) );
$wsApi->setCache( $cache );

// Logging.
/*
$logger = new Monolog\Logger( 'WikisourceApi' );
$logger->pushHandler( new Monolog\Handler\StreamHandler( 'php://stdout', Monolog\Logger::DEBUG ) );
$wsApi->setLogger( $logger );
*/

$wikisource = $wsApi->fetchWikisource( 'en' );
$work = $wikisource->getWork( 'The Inn of Dreams' );

echo "\n'" . $work->getWorkTitle() . "'"
	. ' by ' . implode( ', ', $work->getAuthors() )
	. ' was published in ' . $work->getYear()
	. ' by ' . $work->getPublisher()
	. ' and is identified with ' . $work->getWikidataItemNumber() . "\n";

echo "\nSubpages:\n";
foreach ( $work->getSubpages() as $subpageNum => $subpage ) {
	echo sprintf( "%3d. %s\n", $subpageNum + 1, $subpage );
}

echo "\nIndex pages:\n";
foreach ( $work->getIndexPages() as $indexPage ) {
	echo '* ' . $indexPage->getTitle() . "\n  - " . $indexPage->getImage() . "\n";
}
