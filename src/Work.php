<?php
/**
 * This file contains only the Work class.
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
 * A Work is the whole concept of a single book or other creative item on a Wikisource
 *
 * Works are generally in the main namespace, and are composed of a single top-level wiki-page
 * and a series of subpages (one for each chapter, for example). A work (and its subpages) will
 * often be derived from a set of one or more Index Pages.
 */
class Work
{
    /** @var Wikisource The Wikisource on which this Work is hosted */
    protected $wikisource;

    /** @var \Psr\Log\LoggerInterface The logger to use */
    protected $logger;

    /** @var string The Wikidata Q-number of this Work */
    protected $wikidataItem;

    /** @var string[] The raw data from the ws-* microformat elements. */
    protected $microformatData;

    /** @var string The normalized page title. */
    protected $pageTitle;

    /** @var string The page title as it is prior to being normalized. */
    protected $pageTitleInitial;

    /** @var string The actual work title. */
    protected $workTitle;

    /** @var string The year of publication. */
    protected $year;

    /** @var string The name of the publisher. */
    protected $publisher;

    /**
     * Create a new Work from a given page title in a Wikisource.
     * @param Wikisource $wikisource The Wikisource on which this Work is hosted.
     * @param string $pageTitle The name of the work's main page (or any subpage, in which case the
     * top-level page will be determined and used accordingly).
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(Wikisource $wikisource, $pageTitle, LoggerInterface $logger)
    {
        $this->wikisource = $wikisource;
        $this->logger = $logger;
        // If this is a subpage, determine the main-page. This is just a temporary thing until
        // someone calls getPageTitle() and we normalise it; don't want to do that now, in case it's
        // not necessary.
        if (strpos($pageTitle, '/') !== false) {
            $this->pageTitleInitial = substr($pageTitle, 0, strpos($pageTitle, '/'));
        } else {
            $this->pageTitleInitial = $pageTitle;
        }
    }

    /**
     * Get the Wikisource to which this Work belongs.
     * @return Wikisource
     */
    public function getWikisource()
    {
        return $this->wikisource;
    }

    /**
     * Get the normalised wiki page title of the top-level page of this Work
     * @return string
     */
    public function getPageTitle()
    {
        if ($this->pageTitle !== null) {
            return $this->pageTitle;
        }
        $parse = $this->fetchPageParse($this->pageTitleInitial);
        $this->pageTitle = $parse->get('title');
        return $this->pageTitle;
    }

    /**
     * Fetch the parsed page text, templates, and categories of a given page.
     * @param string $title The page to parse.
     * @return Data The page data.
     * @throws UsageException If the page doesn't exist, or something else goes wrong.
     */
    protected function fetchPageParse($title)
    {
        $cacheKey = 'work.' . $title;
        $cacheItem = $this->wikisource->getWikisoureApi()->cacheGet($cacheKey);
        if ($cacheItem !== false) {
            $this->logger->debug("Using cached page parse data for $title");
            return $cacheItem;
        }
        $requestParse = FluentRequest::factory()
                ->setAction('parse')
                ->setParam('page', $title)
                ->setParam('prop', 'text|templates|categories');
        try {
            $pageParse = new Data($this->wikisource->sendApiRequest($requestParse, 'parse'));
        } catch (UsageException $ex) {
            if ($ex->getMessage() === "The page you specified doesn't exist") {
                // Page not found, elaborate on the reason.
                $msg = "The top-level page for \"$this->pageTitleInitial\" does not exist";
                throw new UsageException($ex->getApiCode(), $msg);
            } else {
                throw $ex;
            }
        }
        $this->wikisource->getWikisoureApi()->cacheSet($cacheKey, $pageParse);
        return $pageParse;
    }

    /**
     * Get the Wikidata Q-number for this Work
     *
     * @return string The Wikidata item number with leading 'Q'.
     */
    public function getWikidataItemNumber()
    {
        $cacheKey = 'work.wikidataitem.' . $this->pageTitle;
        $cacheItem = $this->wikisource->getWikisoureApi()->cacheGet($cacheKey);
        if ($cacheItem !== false) {
            $this->logger->debug("Using cached Wikidata number for $this->pageTitle");
            return $cacheItem;
        }
        // Get the Wikidata Item.
        $requestProps = FluentRequest::factory()
                ->setAction('query')
                ->setParam('titles', $this->pageTitle)
                ->setParam('prop', 'pageprops')
                ->setParam('ppprop', 'wikibase_item');
        $pageProps = $this->wikisource->sendApiRequest($requestProps, 'query.pages');
        $pagePropsSingle = new Data(array_shift($pageProps));
        $wikidataItemNumber = $pagePropsSingle->get('pageprops.wikibase_item');
        $this->logger->debug("Caching Wikidata number for $this->pageTitle");
        $this->wikisource->getWikisoureApi()->cacheSet($cacheKey, $wikidataItemNumber, 24 * 60 * 60);
        return $wikidataItemNumber;
    }

    /**
     * Retrieve all 'ws-*' microformat values.
     * @link https://wikisource.org/wiki/Wikisource:Microformat
     * @return string[] An array of values, keyed by the microformat identifier (e.g. 'ws-title').
     */
    public function getMicroformatData()
    {
        if (is_array($this->microformatData)) {
            return $this->microformatData;
        }
        // Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
        $pageHtml = $this->fetchPageParse($this->getPageTitle())->get('text.*');
        $pageCrawler = new Crawler();
        $pageCrawler->addHtmlContent("<div>$pageHtml</div>", 'UTF-8');
        // Pull the microformatted-defined attributes.
        $microformatIds = ['ws-title', 'ws-author', 'ws-year', 'ws-publisher', 'ws-place'];
        $this->microformatData = [];
        foreach ($microformatIds as $i) {
            $el = $pageCrawler->filterXPath("//*[@id='$i']");
            $this->microformatData[$i] = ($el->count() > 0) ? $el->text() : '';
        }
        return $this->microformatData;
    }

    /**
     * Get the Work's title (which may differ from the page's title)
     * @return string|boolean The title, or false if it could not be found.
     */
    public function getWorkTitle()
    {
        $microformatData = $this->getMicroformatData();
        if (!isset($microformatData['ws-title'])) {
            return false;
        }
        $this->workTitle = $microformatData['ws-title'];
        return $this->workTitle;
    }

    /**
     * Get the name of the Work's publisher.
     * @return string|boolean The publisher, or false if it could not be found.
     */
    public function getPublisher()
    {
        $microformatData = $this->getMicroformatData();
        if (!isset($microformatData['ws-publisher'])) {
            return false;
        }
        return $microformatData['ws-publisher'];
    }

    /**
     * Get the Work's Author's names.
     * @return array|boolean An array of Author names, or false if none could be found.
     */
    public function getAuthors()
    {
        $microformatData = $this->getMicroformatData();
        if (!isset($microformatData['ws-author'])) {
            return false;
        }
        $authors = [];
        $authorData = explode('/', $microformatData['ws-author']);
        foreach ($authorData as $a) {
            $authors[] = $a;
        }
        return $authors;
    }

    /**
     * Get the Work's categories.
     * @param boolean $excludeHidden Whether to exclude hidden categories.
     * @return string[] The Work's categories.
     */
    public function getCategories($excludeHidden = true)
    {
        $pageParse = $this->fetchPageParse($this->getPageTitle());
        $categories = [];
        foreach ($pageParse->get('categories') as $cat) {
            if ($excludeHidden && isset($cat['hidden'])) {
                continue;
            }
            $categories = $cat;
        }
        return $categories;
    }

    /**
     * Get the year of publication.
     * @return boolean|string The year, or false if it can't be determined.
     */
    public function getYear()
    {
        $microformatData = $this->getMicroformatData();
        if (!isset($microformatData['ws-year'])) {
            return false;
        }
        return $microformatData['ws-year'];
    }

    /**
     * Get a list of Index pages that used to construct this Work.
     * @return IndexPage[] An array of Index pages.
     */
    public function getIndexPages()
    {
        $indexPages = [];

        // First of all, find all subpages of this Work.
        $f = new MediawikiFactory($this->getWikisource()->getMediawikiApi());
        $pageListGetter = $f->newPageListGetter();
        $subpages = $pageListGetter->getFromPrefix($this->getPageTitle());

        // Then, for each of them, find the list of relevant transclusions.
        foreach ($subpages->toArray() as $subpage) {
            $subpageTitle = $subpage->getPageIdentifier()->getTitle();
            $subpageParse = $this->fetchPageParse($subpageTitle->getText());
            foreach ($subpageParse->get('templates') as $tpl) {
                $tplNsId = (int) $tpl['ns'];
                $title = $tpl['*'];
                $isIndex = $tplNsId === $this->wikisource->getNamespaceId(Wikisource::NS_NAME_INDEX);
                $alreadyFound = array_key_exists($title, $indexPages);
                if ($isIndex && ! $alreadyFound) {
                    $this->logger->debug("Linking an index page: $title");
                    $indexPage = new IndexPage($this->wikisource, $this->logger);
                    $indexPage->loadFromTitle($title);
                    $indexPages[$title] = $indexPage;
                }
            }
        }
        return $indexPages;
    }
}
