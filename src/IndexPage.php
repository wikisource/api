<?php
/**
 * This file contains only the IndexPage class
 *
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use GuzzleHttp\Client;
use Mediawiki\Api\FluentRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An IndexPage is at the core of the proofreading process for a Work on Wikisource
 */
class IndexPage {

	/** @var string[] The metadata of this page: 'pageid', 'ns', 'title', 'canonicalurl', etc. */
	protected $pageInfo;

	/** @var Wikisource The Wikisource that this IndexPage belongs to */
	protected $wikisource;

	/** @var Crawler The HTML scraping system */
	protected $pageCrawler;

	/** @var \Psr\Log\LoggerInterface The logger to use */
	protected $logger;

	/** @var \DateInterval|int The time to keep the cached Index page metadata for. */
	protected $cacheLifetime;

	/**
	 * Create a new IndexPage based on the given Wikisource
	 *
	 * After creating an object of this class, call one of the loadFrom* methods to actually
	 * retrieve the data from the Wikisource.
	 *
	 * @param Wikisource $ws The Wikisource object on which this Index page resides.
	 * @param LoggerInterface|null $logger A logger interface.
	 * @param int|\DateInterval $cacheLifetime The time interval for which to cache the page's
	 * metadata.
	 */
	public function __construct(
		Wikisource $ws, LoggerInterface $logger = null, $cacheLifetime = 3600
	) {
		$this->wikisource = $ws;
		$this->logger = $logger ?: new NullLogger();
		$this->cacheLifetime = $cacheLifetime;
	}

	/**
	 * Get the Wikisource that this IndexPage is hosted on.
	 * @return Wikisource
	 */
	public function getWikisource() {
		return $this->wikisource;
	}

	/**
	 * Whether this page has been loaded yet
	 *
	 * If it hasn't, you need to call one of the load* methods.
	 *
	 * @return bool
	 */
	public function loaded() {
		return isset( $this->pageInfo['pageid'] );
	}

	/**
	 * Load this Index page from an Index page title.
	 * @param string $title The title of the MediaWiki page (including 'Index:' or the equivalent).
	 * @return void
	 */
	public function loadFromTitle( $title ) {
		$url = 'https://' . $this->wikisource->getDomainName() . '/wiki/' . $title;
		$this->loadFromUrl( $url );
	}

	/**
	 * Load this IndexPage's data from a Wikisource URL
	 *
	 * This is useful because Wikidata only stores Index page links as full URLs (i.e. not as
	 * site links).
	 *
	 * @param string $url A fully-qualified URL of any Wikisource page.
	 * @return null
	 * @throws WikisourceApiException If the URL is not for an existing Index page.
	 */
	public function loadFromUrl( $url ) {
		preg_match( "|wikisource.org/wiki/(.*)|i", $url, $matches );
		if ( !isset( $matches[1] ) ) {
			throw new WikisourceApiException( "Unable to find page title in: $url" );
		}
		$title = $matches[1];

		$cacheKey = 'indexpage' . md5( $url );
		$pageInfo = $this->wikisource->getWikisoureApi()->cacheGet( $cacheKey );
		if ( $pageInfo !== false ) {
			$this->logger->info( "Using cached page info for $url" );
			$this->pageInfo = $pageInfo;
			return null;
		}

		// Query to make sure the page title exists and is an Index page.
		$req = new FluentRequest();
		$req->setAction( 'query' );
		$req->addParams( [ 'titles' => $title, 'prop' => 'info', 'inprop' => 'url' ] );
		$res = $this->wikisource->sendApiRequest( $req, 'query.pages' );
		if ( !isset( $res[0] ) ) {
			throw new WikisourceApiException( "Unable to load IndexPage from URL: $url" );
		}
		$indexPageInfo = $res[0];
		if ( !isset( $indexPageInfo['ns'] ) ) {
			$msg = "Unable to find NS in: " . print_r( $indexPageInfo, true );
			throw new WikisourceApiException( $msg );
		}
		if ( $indexPageInfo['ns'] != $this->wikisource->getNamespaceId( Wikisource::NS_NAME_INDEX ) ) {
			throw new WikisourceApiException( "Page at this URL is not an Index page: $url" );
		}

		$this->pageInfo = $indexPageInfo;
		$this->wikisource->getWikisoureApi()->cacheSet( $cacheKey, $this->pageInfo, 24 * 60 * 60 );
	}

	/**
	 * Get the Index page URL.
	 * @return string The URL.
	 * @throws WikisourceApiException If this is called before one of the load* methods.
	 */
	public function getUrl() {
		if ( !isset( $this->pageInfo['canonicalurl'] ) ) {
			throw new WikisourceApiException( "Index page is not loaded" );
		}
		return $this->pageInfo['canonicalurl'];
	}

	/**
	 * Get the normalised (spaces rather than underscores etc.) version of the title.
	 * @return string
	 * @throws WikisourceApiException If this is called before one of the load* methods.
	 */
	public function getTitle() {
		if ( !isset( $this->pageInfo['title'] ) ) {
			throw new WikisourceApiException( "Index page is not loaded" );
		}
		return $this->pageInfo['title'];
	}

	/**
	 * Get the HTML of the Index page, for further processing. If it's already been fetched, it
	 * won't be re-fetched.
	 * @return Crawler
	 */
	protected function getHtmlCrawler() {
		if ( !$this->pageCrawler instanceof Crawler ) {
			$client = new Client();
			$cacheKey = 'indexpagehtml' . md5( $this->getUrl() );
			$pageHtml = $this->wikisource->getWikisoureApi()->cacheGet( $cacheKey );
			if ( $pageHtml === false ) {
				$indexPage = $client->request( 'GET', $this->getUrl() );
				$pageHtml = $indexPage->getBody()->getContents();
				$this->wikisource->getWikisoureApi()->cacheSet( $cacheKey, $pageHtml, $this->cacheLifetime );
			} else {
				$this->logger->info( "Using cached HTML for index page " . $this->getTitle() );
			}
			$this->pageCrawler = new Crawler;
			$this->pageCrawler->addHTMLContent( $pageHtml, 'UTF-8' );
		}
		return $this->pageCrawler;
	}

	/**
	 * Get a list of all pages: their numbers, labels, statuses, and URLs. Currently doing this in a
	 * pretty clunky way that probably makes quite a few assumptions based on English Wikisource.
	 * This method sends a request to Wikisource.
	 *
	 * @param bool $onlyExisting Only return info about pages that exist. This does not return the
	 * pages' label (it is set to the page number).
	 * @return string[] Array of arrays with keys 'num', 'label', 'status', 'url'.
	 */
	public function getPageList( $onlyExisting = false ) {
		$pagelist = [];

		// Use the API to get a list of all existing pages.
		if ( $onlyExisting ) {
			$req = new FluentRequest();
			$req->setAction( 'query' );
			$indexNsLocalName = $this->wikisource
				->getNamespaceLocalName( Wikisource::NS_NAME_INDEX );
			$title = substr( $this->getTitle(), strlen( $indexNsLocalName ) + 1 );
			$ns = $this->wikisource->getNamespaceId( Wikisource::NS_NAME_PAGE );
			$reqParams = [
				'prop' => 'proofread',
				'generator' => 'prefixsearch',
				'gpssearch' => $title,
				'gpsnamespace' => $ns,
				'gpslimit' => 500,
				'redirects' => true,
			];
			$req->addParams( $reqParams );
			$res = $this->wikisource->sendApiRequest( $req, 'query.pages' );
			foreach ( $res as $page ) {
				$subpage = substr( $page['title'], strrpos( $page['title'], '/' ) + 1 );
				// @TODO The label can not currently be retrieved from the API.
				$pagelist['page-' . $subpage] = [
					'label' => $subpage,
					'num' => $subpage,
					'url' => 'https://' . $this->getWikisource()->getDomainName() . '/wiki/' . $page['title'],
					'quality' => $page['proofread']['quality'],
					'title' => $page['title'],
				];
			}
			return $pagelist;
		}

		// If we need non-existing pages as well, we have to scrape the HTML. :-(
		preg_match( '/(.*wikisource.org)/', $this->pageInfo['canonicalurl'], $matches );
		$baseUrl = isset( $matches[1] ) ? $matches[1] : false;

		$pageCrawler = $this->getHtmlCrawler();
		$pagelistAnchors = $pageCrawler->filterXPath( "//div[contains(@class, 'index-pagelist')]//a" );
		foreach ( $pagelistAnchors as $pageLink ) {
			// Get page URL (which is relative, starting with /w/index.php) and page number.
			$anchorHref = $pageLink->getAttribute( 'href' );
			preg_match( '/\/(\d+)/', $anchorHref, $matches );
			$anchorPageNum = isset( $matches[0] ) ? $matches[1] : false;

			// Get page title (extract from URL).
			preg_match( '|/wiki/(.*)|', $anchorHref, $matches );
			$pageTitle = isset( $matches[0] ) ? $matches[1] : false;
			if ( !$pageTitle ) {
				preg_match( '/title=(.*\/\d+)/', $anchorHref, $matches );
				$pageTitle = isset( $matches[0] ) ? $matches[1] : false;
			}

			// Get quality.
			$anchorClass = $pageLink->getAttribute( 'class' );
			preg_match( '/quality([0-9])/', $anchorClass, $matches );
			$quality = isset( $matches[0] ) ? $matches[1] : false;

			// Save for later.
			$pagelist['page-' . $anchorPageNum] = [
				'label' => $pageLink->nodeValue,
				'num' => $anchorPageNum,
				'url' => $baseUrl . $anchorHref,
				'quality' => $quality,
				'title' => $pageTitle,
			];
		}
		return $pagelist;
	}

	/**
	 * Get information about a particular child page.
	 * @param string $search The info to search for.
	 * @param string $key The key to search by (see return info params).
	 * @return string[]|bool Info: 'label', 'num', 'url', 'quality', and 'title', or false if
	 * a page could not be found with the given criteria.
	 */
	public function getChildPageInfo( $search, $key = 'num' ) {
		$pageList = $this->getPageList();
		foreach ( $pageList as $p ) {
			if ( $p[$key] == $search ) {
				return $p;
			}
		}
		return false;
	}

	/**
	 * The quality of an Index page is taken to be the quality of its lowest
	 * quality page (excluding quality 0, which means "without text").
	 * @link https://en.wikisource.org/wiki/Help:Page_status
	 * @return int The quality rating.
	 */
	public function getQuality() {
		// Starting at the lowest, see if we can find at least one page with the given quality.
		for ( $q = 1; $q <= 4; $q++ ) {
			$quals = $this->getHtmlCrawler()->filterXPath( "//a[contains(@class, 'prp-pagequality-$q')]" );
			if ( $quals->count() > 0 ) {
				// If we can, then this is the overall quality of the IndexPage.
				return $q;
			}
		}
	}

	/**
	 * Get the cover image of this IndexPage.
	 * @return string URL of the JPEG image file on upload.wikimedia.org.
	 */
	public function getImage() {
		$crawler = $this->getHtmlCrawler();
		$imgs = $crawler->filterXPath( "//img" );
		$coverRegex = '/\/\/upload\.wikimedia\.org\/(.*?)\.(djvu|pdf)\.jpg/';
		foreach ( $imgs as $img ) {
			preg_match( $coverRegex, $img->getAttribute( 'src' ), $matches );
			if ( isset( $matches[0] ) ) {
				return $matches[0];
			}
		}
	}
}
