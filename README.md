Wikisource API
==============

This is an client API library for retrieving data from [Wikisources](https://wikisource.org/).
It uses data from Wikidata where it can, or MediaWiki API data from specific Wikisources, or resorts to scraping HTML
where that's the only option.

![CI](https://github.com/wikisource/api/workflows/CI/badge.svg)

Features (some are in development and are not yet functional):

* List all Wikisources.
* Get metadata about a single Wikisource: language code, language name (in that language), and information about
  namespaces (their names and IDs).
* List all Works in a Wikisource.
* Get metadata about a single Work on a Wikisource: URL, page name, work title, author, year of publication,
  proofreading quality, and a list of Index Pages used by the Work.
* Get metadata about a single Index Page on a Wikisource: URL, page name, list of Pages, and proofreading quality.
* Get metadata about a single proofreading Page on a Wikisource (i.e. a wiki-page in the Page namespace): URL, wiki-page
  name, page number (in Djvu/PDF), page label (in the Index's page-list), and proofreading quality.

## Installation

Install with [Composer](https://getcomposer.org/):

```shell
$ composer require wikisource/api
```

You might also want [something that implements `psr/log`](https://packagist.org/providers/psr/log-implementation)
and [something that implements `psr/cache`](https://packagist.org/providers/psr/cache-implementation), if you want to
use the logging and caching features of this library.

## Usage

Find all Wikisources:

```php
$wsApi = new \Wikisource\Api\WikisourceApi();
$wikisources = $wsApi->fetchWikisources();
```

Get a single work:

```php
$wsApi = new \Wikisource\Api\WikisourceApi();
$enWs = $wsApi->fetchWikisource('en');
$prideAndPrejudice = $enWs->getWork('Pride and Prejudice');
echo $work->getWorkTitle().' was published in '.$work->getYear();
```

Let it cache results, such as the seldom-changing list of available Wikisources
as derived from Wikidata (any implementation of [PSR-6](http://www.php-fig.org/psr/psr-6/)
works here):

````php
$wsApi = new \Wikisource\Api\WikisourceApi();
$cache = new Pool(new FileSystem(['path' => __DIR__.'/cache']));
$wsApi->setCache($cache);
````

See the `examples/` directory for fully-functioning examples
that you can run straight away from the command line.

## Caching

Every external request that this library performs will be cached
if you provide a cache pool via `WikisourceApi::setCache()`.
The default cache times are as follows:

| Data          | Default lifetime  | Override      |
| ------------- | ----------------- | ------------- |
| List of Wikisources | 30 days | Parameter to `WikisourceApi::getWikisources()` |
| Index Page metadata | 1 hour | Parameter to `IndexPage::__construct()` |
| A Work's Wikidata Item number | 1 day | *Not possible* |

## Logging

You can enable logging by passing `WikisourceApi::setLogger()` any object
that implements [PSR-3's](http://www.php-fig.org/psr/psr-3/) `LoggerInterface`.

## Issues

Please report all issues via Phabricator (tag them with the
['wikisource-api' project](https://phabricator.wikimedia.org/project/view/2349/)).

## Licence

Copyright © 2016 Sam Wilson and other contributors.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

http://www.gnu.org/copyleft/gpl.html
