<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * An attribute on a handler that declares one of its responses (design/06-json-and-openapi.md).
 */
interface ResponseDeclaration
{
    public function responseDescription(): ResponseDescription;
}
