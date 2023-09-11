<?php
/**
 * This file includes only the WikisourceApi class.
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * This class is the starting-point of the wikisource/api library, from which all other features
 * can be accessed.
 */
class WikisourceApi {

	/** @var CacheItemPoolInterface The cache pool. */
	protected $cachePool;

	/** @var \Psr\Log\LoggerInterface The logger to use. */
	protected $logger;

	/**
	 * Construct a new WikisourceApi object. The logger will default to NullLogger until you set
	 * something else via WikisourceApi::setLogger().
	 */
	public function __construct() {
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
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Enable caching
	 * @param CacheItemPoolInterface $pool The cache pool.
	 * @return void
	 */
	public function setCache( CacheItemPoolInterface $pool ) {
		$this->cachePool = $pool;
	}

	/**
	 * Cache a value (if caching is in use; otherwise do nothing).
	 * @param string $key The cache item's key (i.e. a unique name for it).
	 * @param mixed $value A value supported by the cache system.
	 * @param int|\DateInterval $lifetime The lifetime of the cached item.
	 * @return void
	 */
	public function cacheSet( $key, $value, $lifetime = 3600 ) {
		if ( $this->cachePool !== null ) {
			$this->logger->debug( "Caching $key for " . number_format( $lifetime / 60 ) . " minutes" );
			$cacheItem = $this->cachePool->getItem( $key )
				->set( $value )
				->expiresAfter( $lifetime );
			$this->cachePool->save( $cacheItem );
		}
	}

	/**
	 * Retrieve an item from the cache, if caching is in use
	 *
	 * This is no good for cached items that are strictly equal to false.
	 *
	 * @param string $key The cache key.
	 * @return mixed|bool Either the cached data, or false if no data was found.
	 */
	public function cacheGet( $key ) {
		if ( $this->cachePool === null ) {
			return false;
		}
		$this->logger->debug( "Getting cache item $key" );
		// Fluent interface doesn't work here because AbstractLogger::__destruct() will be called
		// too soon.
		$item = $this->cachePool->getItem( $key );
		if ( $item->isHit() ) {
			return $item->get();
		}
		$this->logger->debug( "$key is not in the cache" );
		return false;
	}

	/**
	 * Get a list of all Wikisources
	 *
	 * @param int|null $cacheLifetime The life of the cache (if one's in use).
	 * @return Wikisource[]
	 */
	public function fetchWikisources( $cacheLifetime = null ) {
		$data = $this->cacheGet( 'wikisources' );
		if ( $data === false ) {
			$this->logger->debug( "Requesting list of Wikisources from Wikidata" );
			$query =
				"SELECT ?item ?langCode ?langName WHERE { "
				// Instance of Wikisource language edition.
				. "?item wdt:P31 wd:Q15156455 . "
				// Wikimedia language code.
				. "?item wdt:P424 ?langCode . "
				// Language of work or name.
				. "?item wdt:P407 ?lang . "
				// RDF label of the language, in the language.
				// filter for mul wikisource
				. "?lang rdfs:label ?langName . FILTER(LANG(?langName) = ?langCode || ( ?langCode = 'mul' && LANG(?langName) = 'en' )) . " . "}";
			$wdQuery = new WikidataQuery( $query );
			$data = $wdQuery->fetch();
			if ( !is_numeric( $cacheLifetime ) ) {
				$cacheLifetime = 60 * 60 * 24 * 30;
			}
			$this->logger->debug( "Caching list of Wikisources for $cacheLifetime" );
			$this->cacheSet( 'wikisources', $data, $cacheLifetime );
		}
		$wikisources = [];
		foreach ( $data as $langInfo ) {
			$ws = new Wikisource( $this, $this->logger );
			$ws->setLanguageCode( $langInfo['langCode'] );
			$ws->setLanguageName( $langInfo['langName'] );
			$ws->setWikidataId( substr( $langInfo['item'], strlen( 'http://www.wikidata.org/entity/' ) ) );
			$wikisources[] = $ws;
		}
		return $wikisources;
	}

	/**
	 * Get a single Wikisource by language code.
	 * @param string $langCode The ISO language code.
	 * @return Wikisource The requested Wikisource.
	 * @throws WikisourceApiException If the requested Wikisource doesn't exist.
	 */
	public function fetchWikisource( $langCode ) {
		foreach ( $this->fetchWikisources() as $ws ) {
			if ( $ws->getLanguageCode() === $langCode ) {
				return $ws;
			}
		}
		throw new WikisourceApiException( "Wikisource '$langCode' does not exist" );
	}

	/**
	 * Get a Wikisource from a given URL.
	 * @param string $url The Wikisource URL, with any path (or none).
	 * @return Wikisource|bool The Wikisource requested, or false if the URL isn't a Wikisource
	 * URL (i.e. xxx.wikisource.org or wikisource.org).
	 */
	public function newWikisourceFromUrl( $url ) {
		// match wikisources with subdomain like xy.wikisource.org or xyz.wikisource.org
		preg_match( '|//([a-z]{0,3})\.?wikisource.org|i', $url, $matches );
		if ( !isset( $matches[1] ) ) {
			$this->logger->debug( "Unable to find Wikisource URL in: $url" );
			return false;
		}
		// if wikisource.org, then set $langCode as mul
		// indicating mul.wikisource.org
		if ( $matches[1] == "" ) {
			$langCode = "mul";
		} else {
			$langCode = $matches[1];
		}
		$ws = new Wikisource( $this, $this->logger );
		$ws->setLanguageCode( $langCode );
		return $ws;
	}

	/**
	 * Get data about a Wikidata entity.
	 * @param string $id The Wikidata ID.
	 * @return bool|mixed
	 */
	public function getWikdataEntity( $id ) {
		$cacheKey = 'wikisourceapi.wikidataentity.' . $id;
		$cacheItem = $this->cacheGet( $cacheKey );
		if ( $cacheItem !== false ) {
			$this->logger->debug( "Using cached data for Wikidata item $id" );
			return $cacheItem;
		}
		$wdApi = new MediawikiApi( 'https://www.wikidata.org/w/api.php' );
		$metadataRequest = new SimpleRequest( 'wbgetentities', [ 'ids' => $id ] );
		$itemResult = $wdApi->getRequest( $metadataRequest );
		if ( !isset( $itemResult['success'] ) || !isset( $itemResult['entities'][$id] ) ) {
			return false;
		}
		$entityData = $itemResult['entities'][ $id ];
		$this->cacheSet( $cacheKey, $entityData );
		return $entityData;
	}
}
