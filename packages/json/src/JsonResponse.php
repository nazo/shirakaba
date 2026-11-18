<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Json\Internal\Planner;

/**
 * Declares a JSON response of a handler with its body type (design/06-json-and-openapi.md):
 *
 *     #[JsonResponse(201, Task::class)]
 *     #[JsonResponse(200, Task::class, list: true)]
 *
 * The body type is derived from the class's constructor with the JSON mapping rules
 * (design/06-json-and-openapi.md), so the class must serialise as its constructor fields (JsonName applies).
 * A #[JsonUnion] type is described as one of its members (design/06-json-and-openapi.md).
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class JsonResponse implements ResponseDeclaration
{
    /**
     * @param class-string $type
     */
    public function __construct(
        public int $status,
        public string $type,
        public bool $list = false,
        public string $description = '',
    ) {}

    public function responseDescription(): ResponseDescription
    {
        // Every field is written, so every field is required in the response.
        $object = ObjectType::forOutput(new Planner(true, sprintf('Response type %s', $this->type))->root($this->type)->type());

        return new ResponseDescription(
            $this->status,
            'application/json',
            $this->list ? new ListType($object) : $object,
            $this->description,
        );
    }
}
