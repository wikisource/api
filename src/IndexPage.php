<?php

namespace Wikisource\Api;

use GuzzleHttp\Client;
use Mediawiki\Api\MediawikiApi;
use Symfony\Component\DomCrawler\Crawler;

class IndexPage
{

    /** @var string The URL of the Index page. */
    protected $url;

    /** @var Crawler */
    protected $pageCrawler;

    /**
     * WikisourceIndexPage constructor.
     * @param $indexPageUrl The URL (URL-encoded).
     */
    public function __construct($indexPageUrl)
    {
        $this->url = $indexPageUrl;
    }

    /**
     * Get the Index page URL.
     * @return string
     */
    public function getUrl()
    {

        return $this->url;
    }

    /**
     * Get the normalised (spaces rather than underscores etc.) version of the title.
     * @return string
     */
    public function getTitle()
    {

        // $title = new Title
        $colonPos = strpos($this->url, ':', strlen('https://'));
        $titlePart = urldecode(substr($this->url, $colonPos + 1));
        return str_replace('_', ' ', $titlePart);
    }

    /**
     * Get the HTML of the Index page, for further processing. If it's allready been fetched, it won't be re-fetched.
     * @return Crawler
     */
    protected function getHtmlCrawler()
    {
        if (!$this->pageCrawler instanceof Crawler) {
            $client = new Client();
            $indexPage = $client->request('GET', $this->url);
            $pageHtml = $indexPage->getBody()->getContents();
            $this->pageCrawler = new Crawler;
            $this->pageCrawler->addHTMLContent($pageHtml, 'UTF-8');
        }
        return $this->pageCrawler;
    }

    /**
     * Get a list of all pages: their numbers, labels, statuses, and URLs. Currently doing this in a pretty clunky way
     * that probably makes quite a few assumptions based on English Wikisource. This method sends a request to
     * Wikisource.
     * @return string[] Array of arrays with keys 'num', 'label', 'status', 'url'.
     */
    public function getPageList()
    {

        preg_match('/(.*wikisource.org)/', $this->url, $matches);
        $baseUrl = isset($matches[1]) ? $matches[1] : false;

        $pageCrawler = $this->getHtmlCrawler();
        $pagelistAnchors = $pageCrawler->filterXPath("//div[contains(@class, 'index-pagelist')]//a");
        $pagelist = [];
        foreach ($pagelistAnchors as $pageLink) {

            // Get page URL (which is relative) and page number.
            // e.g. /w/index.php?title=Page:Gissing_-_The_Emancipated,_vol._I,_1890.djvu/52&action=edit&redlink=1
            $anchorHref = $pageLink->getAttribute('href');
            preg_match('/\/(\d+)/', $anchorHref, $matches);
            $anchorPageNum = isset($matches[0]) ? $matches[1] : false;

            // Get page title (extract from URL).
            preg_match('/title=(.*\/\d+)/', $anchorHref, $matches);
            $pageTitle = isset($matches[0]) ? $matches[1] : false;

            // Get quality.
            $anchorClass = $pageLink->getAttribute('class');
            preg_match('/quality([0-9])/', $anchorClass, $matches);
            $quality = isset($matches[0]) ? $matches[1] : false;

            // Save for later.
            $pagelist['page-'.$anchorPageNum] = [
                'label' => $pageLink->nodeValue,
                'num' => $anchorPageNum,
                'url' => $baseUrl.$anchorHref,
                'quality' => $quality,
                'title' => $pageTitle,
            ];
        }
        return $pagelist;
    }

    /**
     * Get information about a particular child page.
     * @param $search The info to search for.
     * @param string The key to search by (see return info params).
     * @return string[] Info: label, num, url, quality, and title.
     * @return false If a page could not be found with the given criteria.
     * @throws \Exception If the requested page isn't found.
     */
    public function getChildPageInfo($search, $key = 'num')
    {
        $pagelist = $this->getPageList();
        foreach ($pagelist as $p) {
            if ($p[$key] == $search) {
                return $p;
            }
        }
        return false;
    }

    /**
     * The quality of an Index page is taken to be the quality of its lowest
     * quality page (excluding quality 0, which means "without text").
     * @link https://en.wikisource.org/wiki/Help:Page_status
     * @return integer The quality rating.
     */
    public function getQuality()
    {
        for ($q = 1; $q <= 4; $q++) {
            $quals = $this->getHtmlCrawler()->filterXPath("//a[contains(@class, 'prp-pagequality-$q')]");
            if ($quals->count() > 0) {
                return $q;
            }
        }
    }

    /**
     * Get the API URL. This is just a short-term fix.
     * @return string
     */
    public function getApiUrl()
    {

        // <link rel="EditURI" type="application/rsd+xml" href="//en.wikisource.org/w/api.php?action=rsd"/>
        $apiUrl = $this->getHtmlCrawler()->filterXPath("//link[@rel='EditURI']")->attr('href');

        // Remove the suffix.
        $suffix = '?action=rsd';
        if (substr($apiUrl, -strlen($suffix)) === $suffix) {
            $apiUrl = substr($apiUrl, 0, -strlen($suffix));
        }

        // Add protocol.
        if (substr($apiUrl, 0, 2) === '//') {
            $apiUrl = 'https:'.$apiUrl;
        }

        return $apiUrl;
    }
}
