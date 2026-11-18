<?php

declare(strict_types=1);

use Shirakaba\Http\Message\HttpFactory;

// Read by php-http/psr7-integration-tests to build URIs, streams and uploaded files.
define('URI_FACTORY', HttpFactory::class);
define('STREAM_FACTORY', HttpFactory::class);
define('UPLOADED_FILE_FACTORY', HttpFactory::class);
