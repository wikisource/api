<?php

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;

class Wikisource
{
    const NS_NAME_INDEX = 'Index';

    /** @var WikisourceApi */
    protected $api;

    /** @var string */
    protected $langCode;

    /** @var string */
    protected $langName;

    /**
     * Wikisource constructor.
     * @param WikisourceApi $wikisourceApi
     */
    public function __construct(WikisourceApi $wikisourceApi)
    {
        $this->api = $wikisourceApi;
    }

    /**
     * @return WikisourceApi
     */
    public function getWikisoureApi()
    {
        return $this->api;
    }

    public function setLanguageCode($code)
    {
        $this->langCode = $code;
    }
    public function getLanguageCode()
    {
        return $this->langCode;
    }
    public function setLanguageName($name)
    {
        $this->langName = $name;
    }
    public function getLanguageName()
    {
        return $this->langName;
    }

    /**
     * Get a single work from this Wikisource.
     * @param string $pageName The page name to retrieve.
     * @return Work
     */
    public function getWork($pageName)
    {
        return new Work($this, $pageName);
    }

    /**
     * Get an IndexPage object given its URL.
     * @param string $url The URL.
     * @return
     */
    public function getIndexPageFromUrl($url)
    {
        $indexPage = new IndexPage($this);
        $indexPage->loadFromUrl($url);
        return $indexPage;
    }

    /**
     * Get the ID of a namespace.
     * @param string The canonical name of the namespace.
     * @return integer The namespace ID.
     */
    public function getNamespaceId($namespaceName)
    {
        // Find the Index namespace ID.
        $req = FluentRequest::factory()
            ->setAction('query')
            ->setParam('meta', 'siteinfo')
            ->setParam('siprop', 'namespaces');

        $namespaces = $this->sendApiRequest($req, 'query.namespaces');
        $indexNsId = null; // Some don't have ProofreadPage extension installed.
        foreach ($namespaces as $ns) {
            if (isset($ns['canonical']) && $ns['canonical'] === $namespaceName) {
                return $ns['id'];
            }
        }

    }

    /**
     * @return MediawikiApi
     */
    public function getMediawikiApi()
    {
        $api = new MediawikiApi("https://$this->langCode.wikisource.org/w/api.php");
        return $api;
    }

    /**
     * Run an API query on this Wikisource.
     * @param FluentRequest $request The request to send
     * @param string $resultKey The dot-delimited array key of the results (e.g. for a pageprop
     * query, it's 'query.pages').
     * @return array
     */
    public function sendApiRequest(FluentRequest $request, $resultKey)
    {
        $data = [];
        $continue = true;
        do {
            // Send request and save data for later returning.
            $logMsg = "API request: ".json_encode($request->getParams());
            $this->getWikisoureApi()->getLogger()->debug($logMsg);
            $result = new Data($this->getMediawikiApi()->getRequest($request));
            $resultingData = $result->get($resultKey);
            if (!is_array($resultingData)) {
                $continue = false;
                continue;
            }
            $data = array_merge_recursive($data, $resultingData);

            // Whether to continue or not.
            if ($result->get('continue', false)) {
                $request->addParams($result->get('continue'));
            } else {
                $continue = false;
            }
        } while ($continue);

        return $data;
    }
}
