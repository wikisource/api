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
$edition = $wikisource->getEdition( 'The Inn of Dreams' );

echo "\n'" . $edition->getTitle() . "'"
	. ' by ' . implode( ', ', $edition->getAuthors() )
	. ' was published in ' . $edition->getYear()
	. ' by ' . $edition->getPublisher()
	. ' and is identified with ' . $edition->getWikidataItemNumber() . "\n"
	. 'It is an edition of ' . $edition->getWork()->getWikidataId()
	. ", which also has these other editions:\n";
foreach ( $edition->getWork()->getEditions() as $edition ) {
	echo "* " . $edition->getTitle()
		. " (" . $edition->getYear() . ", " . $edition->getWikisource()->getLanguageCode() . ")\n";
}

echo "\nSubpages:\n";
foreach ( $edition->getSubpages() as $subpageNum => $subpage ) {
	echo sprintf( "%3d. %s\n", $subpageNum + 1, $subpage );
}

echo "\nIndex pages:\n";
foreach ( $edition->getIndexPages() as $indexPage ) {
	echo '* ' . $indexPage->getTitle() . "\n  - " . $indexPage->getImage() . "\n";
}
