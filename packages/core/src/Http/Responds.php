<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Attribute;
use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\ResponseDescription;

/**
 * Declares a response a handler produces without a typed body (design/06-json-and-openapi.md), typically an
 * error it throws or an empty success:
 *
 *     #[Responds(404, 'The task does not exist.')]
 *     #[Responds(204)]
 *
 * Error statuses (4xx, 5xx) are documented as Problem Details. A success with a body of
 * another kind names its media type; JSON types (application/json, "+json") are
 * described as any JSON value, others as a string:
 *
 *     #[Responds(200, 'The actor.', contentType: 'application/activity+json')]
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Responds implements ResponseDeclaration
{
    public function __construct(
        public int $status,
        public string $description = '',
        public string $contentType = '',
    ) {}

    public function responseDescription(): ResponseDescription
    {
        if ($this->status >= 400) {
            return new ResponseDescription($this->status, 'application/problem+json', description: $this->description);
        }
        if ($this->contentType === '') {
            return new ResponseDescription($this->status, description: $this->description);
        }
        $mediaType = strtolower(trim(explode(';', $this->contentType, 2)[0]));
        $json = $mediaType === 'application/json' || str_ends_with($mediaType, '+json');

        return new ResponseDescription(
            $this->status,
            $this->contentType,
            $json ? new \Shirakaba\Core\Description\MixedType() : new \Shirakaba\Core\Description\ScalarType('string'),
            $this->description,
        );
    }
}
