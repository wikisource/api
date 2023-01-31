<?php
/**
 * This file contains only the Wikisource class.
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A Wikisource represents a single (one-language) Wikisource site.
 */
class Wikisource {

	/** Wikidata property ID: logo image */
	public const PROP_LOGO = 'P154';

	/** Wikidata property ID: official website */
	public const PROP_WEBSITE = 'P856';

	/** The canonical name of the 'Index' namespace. */
	public const NS_NAME_INDEX = 'Index';

	/** The canonical name of the 'Page' namespace. */
	public const NS_NAME_PAGE = 'Page';

	/** @var WikisourceApi The parent API object. */
	protected $api;

	/** @var string */
	protected $wikidataId;

	/** @var string The language code of this Wikisource. */
	protected $langCode;

	/** @var string The name of the language of this Wikisource, in that language. */
	protected $langName;

	/** @var array Runtime cache of namespace information. */
	protected $namespaces;

	/** @var LoggerInterface The logger to use */
	protected $logger;

	/**
	 * Wikisource constructor
	 * @param WikisourceApi $wikisourceApi The WikisourceApi that this Wikisource is attached to.
	 * @param LoggerInterface|null $logger A logger interface to be used for logging.
	 */
	public function __construct( WikisourceApi $wikisourceApi, LoggerInterface $logger = null ) {
		$this->api = $wikisourceApi;
		$this->logger = $logger ?: new NullLogger();
	}

	/**
	 * Get the parent API object, that this Wikisource was created from.
	 * @return WikisourceApi
	 */
	public function getWikisoureApi() {
		return $this->api;
	}

	/**
	 * Set this Wikisource's language code.
	 * @param string $code The ISO-639 code for this Wikisource's language.
	 * @return void
	 */
	public function setLanguageCode( $code ) {
		$this->langCode = $code;
	}

	/**
	 * Get the ISO-639 language code (and subdomain string) of this Wikisource.
	 * @return string The language code.
	 */
	public function getLanguageCode() {
		return $this->langCode;
	}

	/**
	 * Set this Wikisource's language name, in the language in question.
	 * @param string $name The language name.
	 * @return void
	 */
	public function setLanguageName( $name ) {
		$this->langName = $name;
	}

	/**
	 * Get this Wikisource's language name, in the language in question.
	 * @return string The language name.
	 */
	public function getLanguageName() {
		return $this->langName;
	}

	/**
	 * Get the domain name of this Wikisource.
	 * @return string
	 */
	public function getDomainName(): string {
		$entity = $this->getWikisoureApi()->getWikdataEntity( $this->getWikidataId() );
		if ( isset( $entity['claims'][self::PROP_WEBSITE] ) ) {
			$website = $entity['claims'][self::PROP_WEBSITE][0]['mainsnak']['datavalue']['value'];
			$urlParts = parse_url( $website );
			return $urlParts['host'];
		}
		if ( $this->getLanguageCode() ) {
			return $this->getLanguageCode() . '.wikisource.org';
		}
		return 'wikisource.org';
	}

	/**
	 * @return string
	 */
	public function getWikidataId(): string {
		if ( !$this->wikidataId ) {
			// Get another Wikisource object, but via fetchWikisource() to ensure it has a Wikidata ID.
			$this->wikidataId = $this->getWikisoureApi()
				->fetchWikisource( $this->getLanguageCode() )
				->getWikidataId();
		}
		return $this->wikidataId;
	}

	/**
	 * @param string $wikidataId
	 */
	public function setWikidataId( string $wikidataId ): void {
		$this->wikidataId = $wikidataId;
	}

	/**
	 * Get the Commons filename (without 'File:' prefix) of this Wikisource's logo.
	 * @return string|null
	 */
	public function getLogoFilename(): ?string {
		$entity = $this->getWikisoureApi()->getWikdataEntity( $this->getWikidataId() );
		return isset( $entity['claims'][self::PROP_LOGO] )
			? $entity['claims'][self::PROP_LOGO][0]['mainsnak']['datavalue']['value']
			: null;
	}

	/**
	 * Get a single edition from this Wikisource.
	 * @param string $pageName The top-level page name to retrieve, with or without underscores. If
	 * a subpage is provided, the top-level will be extracted and used.
	 * @return Edition
	 */
	public function getEdition( $pageName ) {
		return new Edition( $this, $pageName, $this->logger );
	}

	/**
	 * Get an IndexPage object given its URL.
	 * @param string $url The URL.
	 * @return IndexPage
	 */
	public function getIndexPageFromUrl( $url ) {
		$indexPage = new IndexPage( $this, $this->logger );
		$indexPage->loadFromUrl( $url );
		return $indexPage;
	}

	/**
	 * Get the ID of a given namespace
	 *
	 * The canonical names of namespaces of interest to Wikisources are defined as constants in
	 * this class, starting with 'NS_NAME_'. Note that not all Wikisources have the ProofreadPage
	 * extension installed, and so requests for Index and Page namespaces will not always work.
	 *
	 * @param string $namespaceName The canonical name of the namespace.
	 * @return int The namespace ID, or false if it can't be found.
	 */
	public function getNamespaceId( $namespaceName ) {
		foreach ( $this->getNamespaces() as $ns ) {
			if ( isset( $ns['canonical'] ) && $ns['canonical'] === $namespaceName ) {
				return $ns['id'];
			}
		}
		return false;
	}

	/**
	 * Get the local name for a single namespace.
	 *
	 * @param string $namespaceName The name of the namespace to get.
	 * @return string
	 */
	public function getNamespaceLocalName( $namespaceName ) {
		if ( $namespaceName === '' ) {
			return '';
		}
		$namespaces = $this->getNamespaces();
		foreach ( $namespaces as $ns ) {
			if ( isset( $ns['canonical'] ) && $ns['canonical'] === $namespaceName && isset( $ns['*'] ) ) {
				return $ns['*'];
			}
		}
	}

	/**
	 * Get information about the namespaces on this Wikisource.
	 *
	 * @return array
	 */
	public function getNamespaces() {
		if ( $this->namespaces ) {
			return $this->namespaces;
		}
		$cacheKey = 'namespaces' . $this->getLanguageCode();
		$namespaces = $this->getWikisoureApi()->cacheGet( $cacheKey );
		if ( $namespaces !== false ) {
			$this->logger->debug( "Using cached namespace data for " . $this->getLanguageCode() );
		} else {
			$this->logger->debug( "Requesting namespace data for " . $this->getLanguageCode() );
			$request = FluentRequest::factory()
				->setAction( 'query' )
				->setParam( 'meta', 'siteinfo' )
				->setParam( 'siprop', 'namespaces' );
			$namespaces = $this->sendApiRequest( $request, 'query.namespaces' );
			$this->getWikisoureApi()->cacheSet( $cacheKey, $namespaces );
		}
		$this->namespaces = $namespaces;
		return $this->namespaces;
	}

	/**
	 * Get a MediawikiApi object for interacting with this Wikisource.
	 * @return MediawikiApi
	 */
	public function getMediawikiApi() {
		$api = new MediawikiApi( 'https://' . $this->getDomainName() . '/w/api.php' );
		return $api;
	}

	/**
	 * Run an API query on this Wikisource.
	 * @param FluentRequest $request The request to send.
	 * @param string $resultKey The dot-delimited array key of the results (e.g. for a pageprop
	 * query, it's 'query.pages').
	 * @return array
	 */
	public function sendApiRequest( FluentRequest $request, $resultKey ) {
		$data = [];
		$continue = true;
		do {
			// Send request and save data for later returning.
			$this->logger->debug( "API request: " . json_encode( $request->getParams() ) );
			$result = new Data( $this->getMediawikiApi()->getRequest( $request ) );
			if ( !$result->has( $resultKey ) ) {
				$continue = false;
				continue;
			}
			$resultingData = $result->get( $resultKey );
			if ( !is_array( $resultingData ) ) {
				$continue = false;
				continue;
			}
			$data = array_merge_recursive( $data, $resultingData );

			// Whether to continue or not.
			if ( $result->get( 'continue', false ) ) {
				$request->addParams( $result->get( 'continue' ) );
			} else {
				$continue = false;
			}
		} while ( $continue );

		return $data;
	}
}
