<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Throwable;

/**
 * Turns exceptions of a library that does not know HTTP into deliberate failures, such as
 * an ORM's "not found" into a 404 (design/08-database.md). Global middleware implementing it is applied
 * automatically; others are added with AppBuilder::translateFailures(). The first translator
 * that returns a failure wins; an exception none of them translates is a 500 (design/05-errors-and-observability.md).
 *
 * Whatever the returned failure carries is shown to clients, so a translator must only pass
 * on messages written for them.
 */
interface TranslatesFailures
{
    public function translate(Throwable $error): ?HttpFailure;
}
