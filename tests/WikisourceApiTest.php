<?php

namespace Wikisource\Api\Test;

use PHPUnit\Framework\TestCase;
use Wikisource\Api\WikisourceApi;

/**
 * @covers \Wikisource\Api\WikisourceApi
 */
class WikisourceApiTest extends TestCase {

	public function testFetchWikisources() {
		$api = new WikisourceApi();
		$wikisources = $api->fetchWikisources();
		$this->assertGreaterThan( 70, $wikisources );
	}

	public function testFetchWikisource() {
		$api = new WikisourceApi();
		$wikisource = $api->fetchWikisource( 'bn' );
		$this->assertSame( 'bn.wikisource.org', $wikisource->getDomainName() );
	}
}
