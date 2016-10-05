<?php

require __DIR__ . '/../vendor/autoload.php';

use Stash\Driver\FileSystem;
use Stash\Pool;

$wsApi = new \Wikisource\Api\WikisourceApi();

// Cache.
$cache = new Pool(new FileSystem(['path' => __DIR__.'/cache']));
$wsApi->setCache($cache);

// Logging.
$logger = new \Apix\Log\Logger\Stream();
$wsApi->setLogger($logger);

$enWs = $wsApi->newWikisourceFromUrl("https://en.wikisource.org/wiki/Any_page");
$prideAndPrejudiceIndex = $enWs->getIndexPageFromUrl(
    "https://en.wikisource.org/wiki/Index:Austen_-_Pride_and_Prejudice,_third_edition,_1817.djvu"
);

$pageList = $prideAndPrejudiceIndex->getPageList();
echo $prideAndPrejudiceIndex->getTitle()
     ." has ".count($pageList) . " pages "
     ."and is of quality '".$prideAndPrejudiceIndex->getQuality()."'\n";
