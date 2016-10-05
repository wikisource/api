<?php

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Symfony\Component\DomCrawler\Crawler;

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

    protected $wikidataItem;

    /**
     * Create a new Work object.
     * @param Wikisource $wikisource
     * @param string $name The name of the work's main page (or any subpage)
     */
    public function __construct(Wikisource $wikisource, $name)
    {
        $this->ws = $wikisource;

        $requestParse =
            FluentRequest::factory()
                ->setAction('parse')
                ->setParam('page', $name)
                ->setParam('prop', 'text|templates|categories');
        $pageParse = new Data($this->ws->sendApiRequest($requestParse, 'parse'));

        // Normalize the page title.
        $this->pageTitle = $pageParse->get('title');
        // If this is a subpage, determine the main-page.
        if (strpos($this->pageTitle, '/') !== false) {
            $this->pageTitle = substr($this->pageTitle, 0, strpos($this->pageTitle, '/'));
        }

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

        // Deal with the data from within the page text.
        // Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
        $pageHtml = $pageParse->get('text.*');
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

    public function getPageTitle()
    {
        return $this->pageTitle;
    }

    public function getWorkTitle()
    {
        return $this->workTitle;
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

    }
}
