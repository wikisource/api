<?php

namespace Wikisource\Api;

use Psr\Log\LoggerInterface;

class Work {

	/** @var string */
	public const PROP_HAS_EDITION = 'P747';

	/** @var WikisourceApi */
	private $wikisourceApi;

	/** @var string */
	private $wikidataId;

	/**
	 * @param WikisourceApi $wikisourceApi
	 * @param string $wikidataId The Wikidata ID.
	 * @param LoggerInterface $logger Logger object.
	 */
	public function __construct( WikisourceApi $wikisourceApi, string $wikidataId, LoggerInterface $logger ) {
		$this->wikisourceApi = $wikisourceApi;
		$this->wikidataId = $wikidataId;
		$this->logger = $logger;
	}

	/**
	 * @return string
	 */
	public function getWikidataId(): string {
		return $this->wikidataId;
	}

	/**
	 * @return Edition[]
	 */
	public function getEditions(): array {
		$entity = $this->wikisourceApi->getWikdataEntity( $this->wikidataId );
		$editions = [];

		// First get all editions linked from the work.
		if ( isset( $entity['claims'][self::PROP_HAS_EDITION] ) ) {
			foreach ( $entity['claims'][self::PROP_HAS_EDITION] as $claim ) {
				$editionWikidataId = $claim['mainsnak']['datavalue']['value']['id'];
				$editionEntity = $this->wikisourceApi->getWikdataEntity( $editionWikidataId );
				foreach ( $editionEntity['sitelinks'] ?? [] as $sitename => $sitelink ) {
					if ( substr( $sitename, -strlen( 'wikisource' ) ) !== 'wikisource' ) {
						continue;
					}
					$langCode = substr( $sitename, 0, -strlen( 'wikisource' ) );
					$ws = $this->wikisourceApi->fetchWikisource( $langCode );
					$editions[] = $ws->getEdition( $sitelink['title'] );
				}
			}
		}

		// Then get all the editions that link *to* the work
		// (and report those that aren't correctly backlinked).

		return $editions;
	}
}
