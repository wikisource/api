<?php

namespace Wikisource\Api;

use Dflydev\DotAccessData\Data;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;

class Wikisource
{
    const NS_NAME_INDEX = 'Index';
    protected $langCode;
    protected $langName;
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

    public function getNsId()
    {
        // Find the Index namespace ID.
        $req = FluentRequest::factory()
            ->setAction('query')
            ->setParam('meta', 'siteinfo')
            ->setParam('siprop', 'namespaces');

        $namespaces = $this->sendApiRequest($req, 'query.namespaces');
        $indexNsId = null; // Some don't have ProofreadPage extension installed.
        foreach ($namespaces as $ns) {
            if (isset($ns['canonical']) && $ns['canonical'] === self::NS_NAME_INDEX) {
                $indexNsId = $ns['id'];
            }
        }

    }

    /**
     * Run an API query on this Wikisource.
     * @param FluentRequest $request The request to send
     * @param string $resultKey The dot-delimited array key of the results
     * @return array
     */
    public function sendApiRequest(FluentRequest $request, $resultKey)
    {
        $api = new MediawikiApi("https://$this->langCode.wikisource.org/w/api.php");
        $data = [];
        $continue = true;
        do {
            // Send request and save data for later returning.
            $result = new Data($api->getRequest($request));
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
