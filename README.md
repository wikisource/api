Wikisource API
==============

This is an API library for retrieving data from [Wikisources](https://wikisource.org/).

## Installation

Install with [Composer](https://getcomposer.org/):

    composer require wikisource/api

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

See the `examples/` directory for fully-functioning examples that you can run straight away from the command line.

## Issues

Please report all issues via [github.com/wikisource/api/issues](https://github.com/wikisource/api/issues)

## Licence

GPL-3.0+
