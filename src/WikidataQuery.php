<?php

namespace Wikisource\Api;

class WikidataQuery
{

    /**
     * @var string The Sparql query to run.
     */
    protected $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * @return string[]
     */
    public function fetch()
    {
        $out = [];
        $result = $this->getXml($this->query);
        foreach ($result->results->result as $res) {
            $out[] = $this->getBindings($res);
        }
        return $out;
    }

    protected function getXml($query)
    {
        $url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=".urlencode($query);
        $client = new Client();
        $response = $client->request('GET', $url);
        return new SimpleXmlElement($response->getBody()->getContents());
    }

    protected function getBindings($xml)
    {
        $out = [];
        foreach ($xml->binding as $binding) {
            if (isset($binding->literal)) {
                $out[(string)$binding['name']] = (string)$binding->literal;
            }
            if (isset($binding->uri)) {
                $out[(string)$binding['name']] = (string)$binding->uri;
            }
        }
        return $out;
    }
}
