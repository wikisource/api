<?php

namespace Wikisource\Api;

use Apix\Log\Logger\LoggerInterface;
use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Psr\Cache\CacheItemPoolInterface;

class WikisourceApi
{

    /** @var CacheItemPoolInterface */
    protected $cachePool;

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;

    /**
     * Set the logger.
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get the logger.
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    public function setCache(CacheItemPoolInterface $pool)
    {
        $this->cachePool = $pool;
    }

    /**
     * Cache a value (if caching is in use; otherwise do nothing).
     * @param string $key The cache item's key (i.e. a unique name for it).
     * @param mixed $value A value supported by the cache system.
     * @param int|\DateInteval The lifetime of the cached item.
     */
    public function cacheSet($key, $value, $lifetime = 3600)
    {
        if ($this->cachePool !== null) {
            $this->getLogger()->debug("Caching $key");
            $cacheItem = $this->cachePool->getItem($key)
                ->set($value)
                ->expiresAfter($lifetime);
            $this->cachePool->save($cacheItem);
        }
    }

    /**
     * Retrieve an item from the cache, if caching is in use.
     * @return mixed
     */
    public function cacheGet($key)
    {
        if ($this->cachePool !== null && $this->cachePool->getItem($key)->isHit()) {
            return $this->cachePool->getItem($key)->get();
        }
        return false;
    }

    /**
     * @return Wikisource[]
     */
    public function fetchWikisources()
    {
        if ($cached = $this->cacheGet('wikisources')) {
            return $cached;
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
            $ws = new Wikisource($this);
            $ws->setLanguageCode($langInfo['langCode']);
            $ws->setLanguageName($langInfo['langName']);
            $wikisources[] = $ws;
        }
        $this->cacheSet('wikisources', $wikisources);
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

    /**
     * Get a Wikisource from a given URL.
     * @param string $url The Wikisource URL, with any path (or none).
     * @return Wikisource|boolean The Wikisource requested, or false if the URL isn't a Wikisource
     * URL (i.e. xxx.wikisource.org).
     */
    public function newWikisourceFromUrl($url)
    {
        preg_match('|//([a-z]{2,3}).wikisource.org|i', $url, $matches);
        if (!isset($matches[1])) {
            $this->getLogger()->debug("Unable to find Wikisource URL in: $url");
            return false;
        }
        $langCode = $matches[1];
        $ws = new Wikisource($this);
        $ws->setLanguageCode($langCode);
        return $ws;
    }
}
