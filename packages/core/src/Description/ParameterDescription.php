<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

use Shirakaba\Core\Extraction\Constraint;

/**
 * What one handler parameter reads from the request (design/04-routing-and-handlers.md).
 */
final readonly class ParameterDescription
{
    public const string PATH = 'path';
    public const string QUERY = 'query';
    public const string HEADER = 'header';
    public const string BODY = 'body';
    /** Filled from application infrastructure (a database lease, a service), not from the request. */
    public const string INTERNAL = 'internal';

    /**
     * @param string $source one of the constants, or a string defined by a package
     * @param list<Constraint> $constraints
     * @param list<string> $mediaTypes
     */
    public function __construct(
        public string $source,
        /** The name in the request: template parameter, query key, header name; "" for a body. */
        public string $name,
        /** The PHP parameter name. */
        public string $parameter,
        public Type $type,
        public bool $required,
        public bool $hasDefault = false,
        public mixed $default = null,
        public array $constraints = [],
        /** Media types a body accepts, such as ["application/json"]. */
        public array $mediaTypes = [],
    ) {}
}
