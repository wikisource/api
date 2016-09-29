<?php

require __DIR__.'/../vendor/autoload.php';

$wsApi = new \Wikisource\Api\WikisourceApi();

echo "Fetching list of Wikisources . . . ";
$wikisources = $wsApi->fetchWikisources();
echo "done.\n".count($wikisources)." Wikisources found:\n";
foreach ($wikisources as $ws) {
    echo "* ".$ws->getLanguageCode()." - ".$ws->getLanguageName()."\n";
}
