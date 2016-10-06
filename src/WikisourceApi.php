<?php
/**
 * This file includes only the WikisourceApi class.
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * Class WikisourceApi
 */
class WikisourceApi
{

    /** @var CacheItemPoolInterface */
    protected $cachePool;

    /** @var \Psr\Log\LoggerInterface The logger to use */
    protected $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * Set the logger
     *
     * There's no corresponding getLogger() method because the Logger is expected to be passed
     * through to objects created within by class.
     * @param LoggerInterface $logger A logger interface if you want to enable logging.
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Enable caching
     * @param CacheItemPoolInterface $pool The cache pool.
     * @return void
     */
    public function setCache(CacheItemPoolInterface $pool)
    {
        $this->cachePool = $pool;
    }

    /**
     * Cache a value (if caching is in use; otherwise do nothing).
     * @param string $key The cache item's key (i.e. a unique name for it).
     * @param mixed $value A value supported by the cache system.
     * @param integer|\DateInterval $lifetime The lifetime of the cached item.
     * @return void
     */
    public function cacheSet($key, $value, $lifetime = 3600)
    {
        if ($this->cachePool !== null) {
            $this->logger->debug("Caching $key for ".number_format($lifetime / 60)." minutes");
            $cacheItem = $this->cachePool->getItem($key)
                ->set($value)
                ->expiresAfter($lifetime);
            $this->cachePool->save($cacheItem);
        }
    }

    /**
     * Retrieve an item from the cache, if caching is in use
     *
     * This is no good for cached items that are strictly equal to false.
     *
     * @param string $key The cache key.
     * @return mixed
     */
    public function cacheGet($key)
    {
        if ($this->cachePool === null) {
            return false;
        }
        $this->logger->debug("Getting cache item $key");
        // Fluent interface doesn't work here because AbstractLogger::__destruct() will be called
        // too soon.
        $item = $this->cachePool->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }
        $this->logger->debug("$key is not in the cache");
        return false;
    }

    /**
     * Get a list of all Wikisources
     *
     * @param integer $cacheLifetime The life of the cache (if one's in use).
     * @return Wikisource[]
     */
    public function fetchWikisources($cacheLifetime = null)
    {
        $cached = $this->cacheGet('wikisources');
        if ($cached) {
            $this->logger->debug("Using cached list of Wikisources");
            return $cached;
        }
        $this->logger->debug("Requesting list of Wikisources from Wikidata");
        $query =
            "SELECT ?langCode ?langName WHERE { "
            // Instance of Wikisource language edition.
            . "?item wdt:P31 wd:Q15156455 . "
            // Wikimedia language code.
            . "?item wdt:P424 ?langCode . "
            // Language of work or name.
            . "?item wdt:P407 ?lang . "
            // RDF label of the language, in the language.
            . "?lang rdfs:label ?langName . FILTER(LANG(?langName) = ?langCode) . "
            . "}";
        $wdQuery = new WikidataQuery($query);
        $data = $wdQuery->fetch();
        $wikisources = [];
        foreach ($data as $langInfo) {
            $ws = new Wikisource($this, $this->logger);
            $ws->setLanguageCode($langInfo['langCode']);
            $ws->setLanguageName($langInfo['langName']);
            $wikisources[] = $ws;
        }
        if (!is_numeric($cacheLifetime)) {
            $cacheLifetime = 60 * 60 * 24 * 30;
        }
        $this->logger->debug("Caching list of Wikisoruces for $cacheLifetime");
        $this->cacheSet('wikisources', $wikisources, $cacheLifetime);
        return $wikisources;
    }

    /**
     * Get a single Wikisource by language code.
     * @param string $langCode The ISO language code.
     * @return Wikisource The requested Wikisource.
     * @throws WikisourceApiException If the requested Wikisource doesn't exist.
     */
    public function fetchWikisource($langCode)
    {
        foreach ($this->fetchWikisources() as $ws) {
            if ($ws->getLanguageCode() === $langCode) {
                return $ws;
            }
        }
        throw new WikisourceApiException("Wikisource '$langCode' does not exist");
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
            $this->logger->debug("Unable to find Wikisource URL in: $url");
            return false;
        }
        $langCode = $matches[1];
        $ws = new Wikisource($this, $this->logger);
        $ws->setLanguageCode($langCode);
        return $ws;
    }
}
