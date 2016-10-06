<?php
/**
 * This file contains only the Work class.
 * @package WikisourceApi
 */

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
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
    protected $ws;

    /** @var string The normalized page title */
    protected $pageTitle;

    /** @var string */
    protected $workTitle;

    /** @var string */
    protected $year;

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;

    /** @var string The Wikidata Q-number of this Work */
    protected $wikidataItem;

    /**
     * Create a new Work object.
     * @param Wikisource $wikisource
     * @param string $pageTitle The name of the work's main page (or any subpage, in which case the
     * top-level page will be determined and used accordingly)
     */
    public function __construct(Wikisource $wikisource, $pageTitle)
    {
        $this->ws = $wikisource;
        // If this is a subpage, determine the main-page. This is just a temporary thing until
        // someone calls getPageTitle() and we normalise it; don't want to do that now, in case it's
        // not necessary.
        if (strpos($pageTitle, '/') !== false) {
            $this->pageTitle = substr($pageTitle, 0, strpos($pageTitle, '/'));
        } else {
            $this->pageTitle = $pageTitle;
        }
    }

    /**
     * Get the normalised wiki page title of the top-level page of this Work
     * @return string
     */
    public function getPageTitle()
    {
        $parse = $this->fetchPageParse();
        $this->pageTitle = $parse->get('title');
        return $this->pageTitle;
    }

    /**
     * @return Data
     */
    protected function fetchPageParse()
    {
        $cacheKey = 'work.'.$this->pageTitle;
        $cacheItem = $this->ws->getWikisoureApi()->cacheGet($cacheKey);
        if ($cacheItem !== false) {
            $this->logger->debug("Using cached page parse data for $this->pageTitle");
            return $cacheItem;
        }
        $requestParse = FluentRequest::factory()
                ->setAction('parse')
                ->setParam('page', $this->pageTitle)
                ->setParam('prop', 'text|templates|categories');
        $pageParse = new Data($this->ws->sendApiRequest($requestParse, 'parse'));
        $this->ws->getWikisoureApi()->cacheSet($cacheKey, $pageParse);
        return $pageParse;
    }

    /**
     * Get the Wikidata Q-number for this Work
     *
     * @return string The Wikidata item number with leading 'Q'.
     */
    public function getWikidataItemNumber()
    {
        $pageParse = $this->fetchPageParse();
        // Get the Wikidata Item.
        $requestProps =
            FluentRequest::factory()
                ->setAction('query')
                ->setParam('titles', $this->pageTitle)
                ->setParam('prop', 'pageprops')
                ->setParam('ppprop', 'wikibase_item');
        $pageProps = $this->ws->sendApiRequest($requestProps, 'query.pages');
        $pagePropsSingle = new Data(array_shift($pageProps));
        $this->wikidataItem = $pagePropsSingle->get('pageprops.wikibase_item');
    }

    /**
     * Get the Work's title (which may differ from the page's title)
     * @return string
     */
    public function getWorkTitle()
    {
        // Deal with the data from within the page text.
        // Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
        $pageHtml = $this->fetchPageParse()->get('text.*');
        $pageCrawler = new Crawler();
        $pageCrawler->addHTMLContent("<div>$pageHtml</div>", 'UTF-8');
        // Pull the microformatted-defined attributes.
        $microformatIds = ['ws-title', 'ws-author', 'ws-year'];
        $microformatVals = [];
        foreach ($microformatIds as $i) {
            $el = $pageCrawler->filterXPath("//*[@id='$i']");
            $microformatVals[$i] = ($el->count() > 0) ? $el->text() : '';
        }
        $this->workTitle = $microformatVals['ws-title'];
        $this->year = $microformatVals['ws-year'];
        return $this->workTitle;

        // Save the authors.
//        $authors = explode('/', $microformatVals['ws-author']);
//        foreach ($authors as $author) {
//
//        }

        // Link the Index pages (i.e. 'templates' that are in the right NS.).
//        foreach ($pageParse->get('templates') as $tpl) {
//            if ($tpl['ns'] === (int) $this->currentLang->index_ns_id) {
//                $this->writeDebug(" -- Linking an index page: " . $tpl['*']);
//                $indexPageName = $tpl['*'];
//                $indexPageId = $this->getOrCreateRecord('index_pages', $indexPageName);
//                $sqlInsertIndexes = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
//                $this->db->query($sqlInsertIndexes, ['ip' => $indexPageId, 'w' => $workId]);
//                $this->getIndexPageMetadata($indexPageName, $workId);
//            }
//        }
//
//        // Save the categories.
//        foreach ($pageParse->get('categories') as $cat) {
//            if (isset($cat['hidden'])) {
//                continue;
//            }
//        }
    }

    public function getYear()
    {
        return $this->year;
    }

    /**
     * Get a list of Index pages that used to construct this Work.
     * @return IndexPage[]
     */
    public function getIndexPages()
    {
        $pageParse = $this->fetchPageParse();
        $indexPages = [];
        foreach ($pageParse->get('templates') as $tpl) {
            if ((int)$tpl['ns'] === $this->ws->getNamespaceId(Wikisource::NS_NAME_INDEX)) {
                $this->logger->debug("Linking an index page: " . $tpl['*']);
                echo $indexPageName = $tpl['*'];
                $indexPage = new IndexPage($this->ws, $this->logger);
                //$indexPage->l
                $indexPages[] = $indexPage;
            }
        }
        return $indexPages;
    }
}
