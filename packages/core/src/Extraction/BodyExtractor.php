<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

/**
 * An extractor that consumes the request body.
 *
 * A handler may have at most one, so the body is read once. It may be any parameter; it is
 * resolved after the others.
 * The core ships none; JSON, form and multipart extractors live in packages.
 */
interface BodyExtractor extends ParameterExtractor {}
