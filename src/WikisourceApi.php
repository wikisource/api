<?php

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Psr\Cache\CacheItemPoolInterface;

class WikisourceApi
{

    /** @var CacheItemPoolInterface */
    protected $cachePool;

    public function __construct()
    {

    }

    public function setCache(CacheItemPoolInterface $pool)
    {
        $this->cachePool = $pool;
    }

    /**
     * @return Wikisource[]
     */
    public function fetchWikisources()
    {
        if ($this->cachePool !== null && $this->cachePool->getItem('wikisources')->isHit()) {
            return $this->cachePool->getItem('wikisources')->get();
        }
        $query =
            "SELECT ?langCode ?langName WHERE { "
            // Instance of Wikisource language edition
            . "?item wdt:P31 wd:Q15156455 . "
            // Wikimedia language code
            . "?item wdt:P424 ?langCode . "
            // language of work or name
            . "?item wdt:P407 ?lang . "
            // RDF label of the language, in the language
            . "?lang rdfs:label ?langName . FILTER(LANG(?langName) = ?langCode) . "
            . "}";
        $wdQuery = new WikidataQuery($query);
        $data = $wdQuery->fetch();
        $wikisources = [];
        foreach ($data as $langInfo) {
            $ws = new Wikisource();
            $ws->setLanguageCode($langInfo['langCode']);
            $ws->setLanguageName($langInfo['langName']);
            $wikisources[] = $ws;
        }
        if ($this->cachePool !== null) {
            $cacheItem = $this->cachePool->getItem('wikisources')->set($wikisources);
            $this->cachePool->save($cacheItem);
        }
        return $wikisources;
    }

    /**
     * Get a single Wikisource by language code.
     * @param string $langCode The ISO language code.
     * @return Wikisource The requested Wikisource.
     * @throws Exception If the requested Wikisource doesn't exist
     */
    public function fetchWikisource($langCode)
    {
        foreach ($this->fetchWikisources() as $ws) {
            if ($ws->getLanguageCode() === $langCode) {
                return $ws;
            }
        }
        throw new Exception("Wikisource '$langCode' does not exist");
    }
}
