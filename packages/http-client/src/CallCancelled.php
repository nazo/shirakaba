<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Shirakaba\Core\Scope\RequestCancelled;

/**
 * The request the client is bound to was cancelled while a call was in flight: a
 * RequestCancelled for the framework, and a ClientExceptionInterface for PSR-18 callers, so
 * sendRequest() throws only what PSR-18 allows.
 */
final class CallCancelled extends RequestCancelled implements ClientExceptionInterface {}
