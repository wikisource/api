<?php
/**
 * This file contains only the Edition class.
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\Api\UsageException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An Edition is a single book or other item on a Wikisource.
 *
 * Editions are generally in the main namespace, and are composed of a single top-level wiki-page
 * and a series of subpages (one for each chapter, for example). An edition (and its subpages) will
 * often be derived from a set of one or more Index Pages. On wiki Editions are often referred to
 * as 'works' but within this package we keep that for the higher-level concept that is modelled on
 * Wikidata and can contain multiple editions.
 */
class Edition {

	/** @var string Wikidata ID for the 'edition or translation of' property. */
	public const PROP_EDITION_OF = 'P629';

	/** @var Wikisource The Wikisource on which this Edition is hosted */
	protected $wikisource;

	/** @var LoggerInterface The logger to use */
	protected $logger;

	/** @var string The Wikidata Q-number of this Edition */
	protected $wikidataItem;

	/** @var string[] The raw data from the ws-* microformat elements. */
	protected $microformatData;

	/** @var string The normalized page title. */
	protected $pageTitle;

	/** @var string The page title as it is prior to being normalized. */
	protected $pageTitleInitial;

	/** @var string The actual edition title. */
	protected $title;

	/** @var string The year of publication. */
	protected $year;

	/** @var string The name of the publisher. */
	protected $publisher;

	/**
	 * Create a new Edition object from a given page title in a Wikisource.
	 * @param Wikisource $wikisource The Wikisource on which this Edition is hosted.
	 * @param string $pageTitle The name of the Edition's main page (or any subpage, in which case
	 * the top-level page will be determined and used accordingly).
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct( Wikisource $wikisource, $pageTitle, LoggerInterface $logger ) {
		$this->wikisource = $wikisource;
		$this->logger = $logger;
		// If this is a subpage, determine the main-page. This is just a temporary thing until
		// someone calls getPageTitle() and we normalise it; don't want to do that now, in case it's
		// not necessary.
		if ( strpos( $pageTitle, '/' ) !== false ) {
			$this->pageTitleInitial = substr( $pageTitle, 0, strpos( $pageTitle, '/' ) );
		} else {
			$this->pageTitleInitial = $pageTitle;
		}
	}

	/**
	 * Get the Wikisource to which this Edition belongs.
	 * @return Wikisource
	 */
	public function getWikisource() {
		return $this->wikisource;
	}

	/**
	 * Get the work for this edition. This is based on the Wikidata property only.
	 * @return Work|bool The Work object, or false if it couldn't be determined.
	 */
	public function getWork() {
		$entity = $this->getEntity();
		if ( !isset( $entity['claims'][self::PROP_EDITION_OF] ) ) {
			$this->logger->notice( $this->getWikidataItemNumber() . ' does not have ' . self::PROP_EDITION_OF );
			return false;
		}
		$workId = $entity['claims'][self::PROP_EDITION_OF][0]['mainsnak']['datavalue']['value']['id'];
		return new Work( $this->getWikisource()->getWikisoureApi(), $workId, $this->logger );
	}

	/**
	 * Get the wiki page title of the top-level page of this Edition.
	 * @param bool $normalize Whether to send a request for the normalized title. If false, the
	 * title as provided to the constructor will be returned (sans subpages). If the title has
	 * previously been normalized it will be returned normalized (without submitting another
	 * request though).
	 * @return string
	 */
	public function getPageTitle( $normalize = true ) {
		if ( $this->pageTitle !== null ) {
			return $this->pageTitle;
		}
		if ( !$normalize ) {
			return $this->pageTitleInitial;
		}
		$parse = $this->fetchPageParse( $this->pageTitleInitial );
		$this->pageTitle = $parse->get( 'title' );
		return $this->pageTitle;
	}

	/**
	 * Fetch the parsed page text, templates, and categories of a given page.
	 * @param string $title The page to parse.
	 * @return Data The page data.
	 * @throws UsageException If the page doesn't exist, or something else goes wrong.
	 */
	protected function fetchPageParse( $title ) {
		$cacheKey = 'edition.' . md5( $title );
		$cacheItem = $this->wikisource->getWikisoureApi()->cacheGet( $cacheKey );
		if ( $cacheItem !== false ) {
			$this->logger->debug( "Using cached page parse data for $title" );
			return $cacheItem;
		}
		$requestParse = FluentRequest::factory()
				->setAction( 'parse' )
				->setParam( 'page', $title )
				->setParam( 'prop', 'text|templates|categories' );
		try {
			$pageParse = new Data( $this->wikisource->sendApiRequest( $requestParse, 'parse' ) );
		} catch ( UsageException $ex ) {
			if ( $ex->getMessage() === "The page you specified doesn't exist" ) {
				// Page not found, elaborate on the reason.
				$msg = "The top-level page for \"$this->pageTitleInitial\" does not exist";
				throw new UsageException( $ex->getApiCode(), $msg );
			} else {
				throw $ex;
			}
		}
		$this->wikisource->getWikisoureApi()->cacheSet( $cacheKey, $pageParse );
		return $pageParse;
	}

	/**
	 * Get the Wikidata Q-number for this Edition
	 *
	 * @return string The Wikidata item number with leading 'Q'.
	 */
	public function getWikidataItemNumber() {
		$pageTitle = $this->getPageTitle();
		$cacheKey = 'edition.wikidataitem.' . $pageTitle;
		$cacheItem = $this->wikisource->getWikisoureApi()->cacheGet( $cacheKey );
		if ( $cacheItem !== false ) {
			$this->logger->debug( "Using cached Wikidata number for $pageTitle" );
			return $cacheItem;
		}
		// Get the Wikidata Item.
		$requestProps = FluentRequest::factory()
				->setAction( 'query' )
				->setParam( 'titles', $pageTitle )
				->setParam( 'prop', 'pageprops' )
				->setParam( 'ppprop', 'wikibase_item' );
		$pageProps = $this->wikisource->sendApiRequest( $requestProps, 'query.pages' );
		$pagePropsSingle = new Data( array_shift( $pageProps ) );
		$wdItemNum = $pagePropsSingle->get( 'pageprops.wikibase_item' );
		$this->logger->debug( "Caching Wikidata number for $pageTitle" );
		$this->wikisource->getWikisoureApi()->cacheSet( $cacheKey, $wdItemNum, 24 * 60 * 60 );
		return $wdItemNum;
	}

	/**
	 * @return mixed[]|bool
	 */
	public function getEntity(): array {
		$qid = $this->getWikidataItemNumber();
		return $this->getWikisource()->getWikisoureApi()->getWikdataEntity( $qid );
	}

	/**
	 * Retrieve all 'ws-*' microformat values.
	 * @link https://wikisource.org/wiki/Wikisource:Microformat
	 * @return string[] An array of values, keyed by the microformat identifier (e.g. 'ws-title').
	 */
	public function getMicroformatData() {
		if ( is_array( $this->microformatData ) ) {
			return $this->microformatData;
		}
		$pageCrawler = $this->getHtmlCrawler( $this->getPageTitle() );
		// Pull the microformatted-defined attributes.
		$microformatIds = [ 'ws-title', 'ws-author', 'ws-year', 'ws-publisher', 'ws-place' ];
		$this->microformatData = [];
		foreach ( $microformatIds as $i ) {
			$el = $pageCrawler->filterXPath( "//*[@id='$i']" );
			$this->microformatData[$i] = ( $el->count() > 0 ) ? $el->text() : '';
		}
		return $this->microformatData;
	}

	/**
	 * Get the Edition's title (which may differ from the top-level page's title). This will attempt
	 * to extract the title from the page microformats; if it fails to do so it will use the page
	 * title instead.
	 *
	 * @return string|bool The title (which may actually just be the page title).
	 */
	public function getTitle() {
		$microformatData = $this->getMicroformatData();
		if ( !isset( $microformatData['ws-title'] ) ) {
			return $this->getPageTitle();
		}
		$this->title = $microformatData['ws-title'];
		return $this->title;
	}

	/**
	 * Get the name of the Edition's publisher.
	 * @return string|bool The publisher, or false if it could not be found.
	 */
	public function getPublisher() {
		$microformatData = $this->getMicroformatData();
		if ( !isset( $microformatData['ws-publisher'] ) ) {
			return false;
		}
		return $microformatData['ws-publisher'];
	}

	/**
	 * Get the Edition's Author's names.
	 * @return array|bool An array of Author names, or false if none could be found.
	 */
	public function getAuthors() {
		$microformatData = $this->getMicroformatData();
		if ( !isset( $microformatData['ws-author'] ) ) {
			return false;
		}
		$authors = [];
		$authorData = explode( '/', $microformatData['ws-author'] );
		foreach ( $authorData as $a ) {
			$authors[] = $a;
		}
		return $authors;
	}

	/**
	 * Get the Edition's categories.
	 * @param bool $excludeHidden Whether to exclude hidden categories.
	 * @return string[] The Edition's categories.
	 */
	public function getCategories( $excludeHidden = true ) {
		$pageParse = $this->fetchPageParse( $this->getPageTitle() );
		$categories = [];
		foreach ( $pageParse->get( 'categories' ) as $cat ) {
			if ( $excludeHidden && isset( $cat['hidden'] ) ) {
				continue;
			}
			$categories = $cat;
		}
		return $categories;
	}

	/**
	 * Get the year of publication.
	 * @return bool|string The year, or false if it can't be determined.
	 */
	public function getYear() {
		$microformatData = $this->getMicroformatData();
		if ( !isset( $microformatData['ws-year'] ) ) {
			return false;
		}
		return $microformatData['ws-year'];
	}

	/**
	 * Get a list of Index pages that used to construct this Edition.
	 * @return IndexPage[] An array of Index pages.
	 */
	public function getIndexPages() {
		$indexPages = [];

		// First of all, find all subpages of this Edition.
		$f = new MediawikiFactory( $this->getWikisource()->getMediawikiApi() );
		$pageListGetter = $f->newPageListGetter();
		$subpages = $pageListGetter->getFromPrefix( $this->getPageTitle() );

		// Then, for each of them, find the list of relevant transclusions.
		foreach ( $subpages->toArray() as $subpage ) {
			$subpageTitle = $subpage->getPageIdentifier()->getTitle();
			$subpageParse = $this->fetchPageParse( $subpageTitle->getText() );
			foreach ( $subpageParse->get( 'templates' ) as $tpl ) {
				$tplNsId = (int)$tpl['ns'];
				$title = $tpl['*'];
				$isIndex = $tplNsId === $this->wikisource->getNamespaceId( Wikisource::NS_NAME_INDEX );
				$alreadyFound = array_key_exists( $title, $indexPages );
				if ( $isIndex && !$alreadyFound ) {
					$this->logger->debug( "Linking an index page: $title" );
					$indexPage = new IndexPage( $this->wikisource, $this->logger );
					$indexPage->loadFromTitle( $title );
					$indexPages[$title] = $indexPage;
				}
			}
		}
		return $indexPages;
	}

	/**
	 * Get a DOM crawler for the parsed page HTML.
	 * @param string $title The page title.
	 * @return Crawler
	 */
	protected function getHtmlCrawler( $title ) {
		$pageHtml = $this->fetchPageParse( $title )->get( 'text.*' );
		$pageCrawler = new Crawler();
		// Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
		$pageCrawler->addHtmlContent( "<div>$pageHtml</div>", 'UTF-8' );
		return $pageCrawler;
	}

	/**
	 * Get all of this Edition's subpages, in the order in which they appear in the wiki pages.
	 *
	 * Some Edition have many thousands of subpages, so in many situations its not feasible (or
	 * desired) to scan them all. If you want just their names and don't care about ordering,
	 * there are better ways than this.
	 *
	 * @param int $limit Give up after finding this many subpages.
	 * reasonable number.
	 *
	 * @return string[]
	 */
	public function getSubpages( $limit = 100 ) {
		return $this->getSubpagesData( $this->getPageTitle(), $limit );
	}

	/**
	 * Internal recursive method for getting the names of subpages of this Edition.
	 * @param string $title The highest-level page under which to get subpages.
	 * @param int $limit Give up after finding this many subpages.
	 * @param string[] &$visited Pages to ignore when recursing into subpages' links.
	 * @return array
	 */
	protected function getSubpagesData( $title, $limit, &$visited = [] ) {
		$this->logger->debug( "Getting subpages of $title" );
		$subpages = [];
		$pageCrawler = $this->getHtmlCrawler( $title );
		$links = $pageCrawler->filterXPath( '//a' );
		$baseHref = '/wiki/' . str_replace( ' ', '_', $title );
		foreach ( $links as $link ) {
			$href = urldecode( $link->getAttribute( 'href' ) );
			// Ignore redirects.
			if ( $link->getAttribute( 'class' ) == 'mw-redirect' ) {
				$this->logger->debug( "Ignoring redirect to $href" );
				continue;
			}
			// Ignore if doesn't start with our base URL.
			if ( substr( $href, 0, strlen( $baseHref ) ) !== $baseHref ) {
				continue;
			}
			// Drop the wiki prefix and the fragment.
			$pageTitle = substr( urldecode( $href ), strlen( '/wiki/' ) );
			$fragmentPos = strrpos( $pageTitle, '#' );
			if ( $fragmentPos !== false ) {
				$pageTitle = substr( $pageTitle, 0, $fragmentPos );
			}
			// Go to the next page if we've already visited this one.
			if ( in_array( $pageTitle, $visited ) ) {
				continue;
			}
			// Record that we've scanned this page.
			$visited[] = $pageTitle;
			$subpages = array_merge(
				$subpages,
				[ $pageTitle ]
			);
			// Return what we've got so far if we're at the limit.
			if ( count( $subpages ) >= $limit ) {
				$this->logger->debug( "Reached limit of $limit subpages after adding $pageTitle" );
				return $subpages;
			}
			// Recurse to get the subpages linked from this subpage.
			$subpages = array_merge(
				$subpages,
				$this->getSubpagesData( $pageTitle, $limit, $visited )
			);
		}
		return $subpages;
	}
}
