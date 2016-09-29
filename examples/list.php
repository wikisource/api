<?php

require __DIR__ . '/../vendor/autoload.php';

use Stash\Driver\FileSystem;
use Stash\Pool;

$wsApi = new \Wikisource\Api\WikisourceApi();

$cache = new Pool(new FileSystem(['path' => __DIR__.'/cache']));
$wsApi->setCache($cache);

echo "Fetching (and caching) list of Wikisources . . . ";
$wikisources = $wsApi->fetchWikisources();
echo "done.\n".count($wikisources)." Wikisources found:\n";
foreach ($wikisources as $ws) {
    echo "* ".$ws->getLanguageCode()." - ".$ws->getLanguageName()."\n";
}
