<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

use Shirakaba\Core\Description\ApplicationDescription;
use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\EventChannelDescription;
use Shirakaba\Core\Description\EventStreamType;
use Shirakaba\Core\Description\FileType;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\MapType;
use Shirakaba\Core\Description\MixedType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\RouteDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\Core\Extraction\Constraint;
use stdClass;

/**
 * Builds an OpenAPI 3.1 document from an application description (design/06-json-and-openapi.md).
 *
 * The only input is the core's metadata API; nothing is discovered or guessed. What
 * cannot be described is left out and reported as a warning.
 */
final readonly class Generator
{
    private const string VERSION = '3.1.1';

    /** The source used by shirakaba/auth's #[Authenticated] (design/09-security.md). */
    private const string BEARER = 'bearer';

    /**
     * @var list<string>
     */
    private const array METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'OPTIONS', 'HEAD', 'PATCH', 'TRACE'];

    /**
     * @param array<string, Operation> $operations documentation by route name
     * @param list<string> $servers server URLs
     * @param list<EventChannelDescription> $eventChannels channels served by a hub, written as
     *                                                    the x-event-channels extension (design/11-real-time-events.md)
     */
    public function __construct(
        private Info $info,
        private array $operations = [],
        private array $servers = [],
        private array $eventChannels = [],
    ) {}

    public function generate(ApplicationDescription $application): Document
    {
        $state = new GeneratorState();
        $paths = [];
        foreach ($application->routes as $route) {
            if (!$route->documented) {
                continue;
            }
            $method = strtolower($route->method);
            if (!in_array($route->method, self::METHODS, true)) {
                $state->warn(sprintf('Route "%s %s": OpenAPI 3.1 cannot describe the method %s.', $route->method, $route->template, $route->method));
                continue;
            }
            $paths[self::path($route->template)][$method] = $this->operation($route, $state);
        }

        $channels = [];
        foreach ($this->eventChannels as $channel) {
            if (isset($channels[$channel->name])) {
                $state->warn(sprintf('Two event channels are named "%s"; the second is left out.', $channel->name));
                continue;
            }
            $entry = [];
            if ($channel->summary !== '') {
                $entry['summary'] = $channel->summary;
            }
            $entry['channel'] = $channel->channel;
            $entry['parameters'] = $channel->parameters === [] ? new stdClass() : array_map(fn (Type $type): array => $this->schema($type, $state), $channel->parameters);
            $entry['private'] = $channel->private;
            $entry['content'] = ['application/json' => ['schema' => $this->schema($channel->payload, $state)]];
            $channels[$channel->name] = $entry;
        }

        $state->schemas['Problem'] = [
            'type' => 'object',
            'description' => 'RFC 9457 Problem Details.',
            'properties' => [
                'type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'code' => ['type' => 'string', 'description' => 'A stable code for the problem, such as invalid-body.'],
                'errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'pointer' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'code' => ['type' => 'string', 'description' => 'A stable code for the field error, such as required or invalid-date-time.'],
                        ],
                        'required' => ['pointer', 'detail'],
                    ],
                ],
            ],
            'required' => ['type', 'title', 'status'],
        ];
        ksort($state->schemas);

        $info = ['title' => $this->info->title, 'version' => $this->info->version];
        if ($this->info->description !== '') {
            $info['description'] = $this->info->description;
        }

        $document = ['openapi' => self::VERSION, 'info' => $info];
        if ($this->servers !== []) {
            $document['servers'] = array_map(static fn (string $url): array => ['url' => $url], $this->servers);
        }
        $document['paths'] = $paths === [] ? new stdClass() : $paths;
        $components = [];
        if ($state->problemResponse) {
            $components['responses'] = ['Problem' => [
                'description' => 'An error, as RFC 9457 Problem Details.',
                'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ]];
        }
        $components['schemas'] = $state->schemas;
        if ($state->bearer) {
            $components['securitySchemes'] = ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']];
        }
        $document['components'] = $components;
        if ($channels !== []) {
            $document['x-event-channels'] = $channels;
        }

        return new Document($document, $state->warnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RouteDescription $route, GeneratorState $state): array
    {
        $operation = [];
        $documentation = $route->name === null ? null : ($this->operations[$route->name] ?? null);
        if ($route->name !== null) {
            $operation['operationId'] = $route->name;
        }
        if ($documentation !== null) {
            if ($documentation->summary !== '') {
                $operation['summary'] = $documentation->summary;
            }
            if ($documentation->description !== '') {
                $operation['description'] = $documentation->description;
            }
            if ($documentation->tags !== []) {
                $operation['tags'] = $documentation->tags;
            }
            if ($documentation->deprecated) {
                $operation['deprecated'] = true;
            }
        }

        $label = sprintf('Route "%s %s"', $route->method, $route->template);
        $parameters = [];
        $body = null;
        $documented = [];
        $security = null;
        foreach ($route->parameters as $parameter) {
            if ($parameter->source === ParameterDescription::BODY) {
                $body = $parameter;
                continue;
            }
            if ($parameter->source === ParameterDescription::INTERNAL) {
                continue;
            }
            if ($parameter->source === self::BEARER) {
                // Required authentication, or optional (an empty requirement means anonymous is allowed).
                $security = $parameter->required ? [['bearerAuth' => []]] : [['bearerAuth' => []], new stdClass()];
                $state->bearer = true;
                continue;
            }
            if (!in_array($parameter->source, [ParameterDescription::PATH, ParameterDescription::QUERY, ParameterDescription::HEADER, 'cookie'], true)) {
                $state->warn(sprintf('%s: parameter $%s reads from "%s", which OpenAPI cannot describe.', $label, $parameter->parameter, $parameter->source));
                continue;
            }
            if ($parameter->source === ParameterDescription::PATH) {
                $documented[$parameter->name] = true;
            }
            $parameters[] = $this->parameter($parameter, $route->template, $state);
        }
        foreach ($route->templateParameters as $name) {
            if (!isset($documented[$name])) {
                // OpenAPI requires every template parameter; the handler simply does not read it.
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]
                    + self::catchAllNote($route->template, $name);
            }
        }
        foreach ($route->undescribed as $name) {
            $state->warn(sprintf('%s: parameter $%s uses an extractor that cannot describe itself; it is left out.', $label, $name));
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $schema = $this->schema($body->type, $state);
            $operation['requestBody'] = [
                'required' => true,
                'content' => array_fill_keys($body->mediaTypes === [] ? ['application/octet-stream'] : $body->mediaTypes, ['schema' => $schema]),
            ];
        }

        if ($security !== null) {
            $operation['security'] = $security;
        }
        $operation['responses'] = $this->responses($route, $parameters !== [] || $body !== null, $body, $state);
        if ($security !== null) {
            $operation['responses']['401'] ??= ['$ref' => '#/components/responses/Problem'];
            ksort($operation['responses'], SORT_STRING);
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(ParameterDescription $parameter, string $template, GeneratorState $state): array
    {
        // A path, query string or header cannot carry null: a nullable parameter is only
        // optional (with a null default) or still required (without one, design/04-routing-and-handlers.md).
        $schema = self::withConstraints(self::withoutNull($this->schema($parameter->type, $state)), $parameter->constraints);
        if ($parameter->hasDefault && $parameter->default !== null) {
            $schema['default'] = $parameter->default;
        }

        $result = [
            'name' => $parameter->name,
            'in' => $parameter->source,
            'required' => $parameter->source === ParameterDescription::PATH || $parameter->required,
            'schema' => $schema,
        ];

        return $parameter->source === ParameterDescription::PATH ? $result + self::catchAllNote($template, $parameter->name) : $result;
    }

    /**
     * Status codes become int keys in PHP; JSON encodes the array as an object anyway,
     * because the keys never form a list.
     *
     * @return array<array-key, mixed>
     */
    private function responses(RouteDescription $route, bool $hasInput, ?ParameterDescription $body, GeneratorState $state): array
    {
        $responses = [];
        $problem = ['$ref' => '#/components/responses/Problem'];
        $declaredSuccess = array_filter($route->declaredResponses, static fn ($response): bool => $response->status < 400);
        foreach ($route->declaredResponses as $declared) {
            $status = (string) $declared->status;
            if ($declared->status >= 400) {
                // OpenAPI 3.1 allows a description next to a reference.
                $responses[$status] = $declared->description === '' ? $problem : [...$problem, 'description' => $declared->description];
                continue;
            }
            $responses[$status] ??= ['description' => $declared->description !== '' ? $declared->description : 'Successful response.'];
            if ($declared->contentType !== '') {
                $responses[$status]['content'][$declared->contentType] = $declared->body === null
                    ? ['schema' => ['description' => 'Any JSON value.']]
                    : $this->mediaType($declared->body, $state);
            }
        }

        // Declared success responses replace the ones guessed from return types (design/06-json-and-openapi.md).
        foreach ($declaredSuccess === [] ? $route->responses : [] as $class) {
            if (!is_subclass_of($class, DescribesResponse::class)) {
                $responses['200'] ??= ['description' => 'Successful response.'];
                continue;
            }
            $description = $class::describeResponse();
            $status = (string) $description->status;
            $responses[$status] ??= ['description' => $description->status < 300 ? 'Successful response.' : 'Response.'];
            if ($description->contentType !== '') {
                $responses[$status]['content'][$description->contentType] = $description->body === null
                    ? ['schema' => new stdClass()]
                    : $this->mediaType($description->body, $state);
            }
        }

        $state->problemResponse = true;
        if ($hasInput) {
            $responses['400'] ??= $problem;
        }
        if ($body !== null) {
            $responses['413'] ??= $problem;
            $responses['415'] ??= $problem;
            if (self::hasConstraints($body->type)) {
                $responses['422'] ??= $problem;
            }
        }
        $responses['default'] = $problem;
        ksort($responses, SORT_STRING);

        return $responses;
    }

    /**
     * A media type object. An event stream is a string to OpenAPI 3.1; its events are
     * described in x-itemSchema, the shape OpenAPI 3.2 gives itemSchema for
     * text/event-stream, so tools can read it today and the key can drop its prefix once
     * Shirakaba writes 3.2 (design/11-real-time-events.md).
     *
     * @return array<string, mixed>
     */
    private function mediaType(Type $type, GeneratorState $state): array
    {
        if (!$type instanceof EventStreamType) {
            return ['schema' => $this->schema($type, $state)];
        }
        $variants = [];
        foreach ($type->events as $name => $data) {
            $variants[] = [
                'properties' => [
                    'event' => ['const' => (string) $name],
                    'data' => $data instanceof ScalarType
                        ? ['type' => 'string']
                        : ['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => $this->schema($data, $state)],
                ],
            ];
        }

        return [
            'schema' => ['type' => 'string'],
            'x-itemSchema' => [
                'type' => 'object',
                'properties' => [
                    'event' => ['type' => 'string'],
                    'data' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'retry' => ['type' => 'integer', 'minimum' => 0],
                ],
                'required' => ['event', 'data'],
                'oneOf' => $variants,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(Type $type, GeneratorState $state): array
    {
        $schema = match (true) {
            $type instanceof ScalarType => ['type' => match ($type->name) {
                'string' => 'string',
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
            }] + ($type->format === null ? [] : ['format' => $type->format]),
            $type instanceof EnumType => [
                'type' => is_int($type->values[0] ?? null) ? 'integer' : 'string',
                'enum' => $type->values,
            ],
            $type instanceof ListType => ['type' => 'array', 'items' => $this->schema($type->items, $state)],
            $type instanceof MapType => ['type' => 'object', 'additionalProperties' => $this->schema($type->values, $state)],
            $type instanceof ObjectType => ['$ref' => '#/components/schemas/' . $this->component($type, $state)],
            $type instanceof UnionType => ['$ref' => '#/components/schemas/' . $this->unionComponent($type, $state)],
            $type instanceof ConstType => ['type' => 'string', 'const' => $type->value],
            $type instanceof FileType => count($type->mediaTypes) === 1
                ? ['type' => 'string', 'format' => 'binary', 'contentMediaType' => $type->mediaTypes[0]]
                : ['type' => 'string', 'format' => 'binary'],
            // Never an empty array: json_encode() would write "[]" instead of "{}".
            default => ['description' => 'Any JSON value.'],
        };

        if (!$type->isNullable() || $type instanceof MixedType) {
            return $schema;
        }
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }
        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    /**
     * Undoes what schema() adds for a nullable type.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function withoutNull(array $schema): array
    {
        // schema() writes a nullable reference as anyOf: [{$ref}, {type: null}].
        $anyOf = $schema['anyOf'] ?? null;
        if (is_array($anyOf) && count($anyOf) === 2 && ($anyOf[1] ?? null) === ['type' => 'null'] && is_array($anyOf[0] ?? null) && is_string($anyOf[0]['$ref'] ?? null)) {
            return ['$ref' => $anyOf[0]['$ref']];
        }
        if (is_array($schema['type'] ?? null)) {
            $types = array_values(array_filter($schema['type'], static fn (mixed $type): bool => $type !== 'null'));
            $schema['type'] = count($types) === 1 ? $types[0] : $types;
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $schema['enum'] = array_values(array_filter($schema['enum'], static fn (mixed $value): bool => $value !== null));
        }

        return $schema;
    }

    /**
     * A tagged union (design/06-json-and-openapi.md): oneOf its members with a discriminator mapping each value to
     * the member's component.
     */
    private function unionComponent(UnionType $type, GeneratorState $state): string
    {
        $name = $state->componentNames[$type->class . ($type->output ? ' output' : ' input')] ?? null;
        if ($name !== null) {
            return $name;
        }
        $name = self::componentName($type->class, $type->name, $type->output, $state);

        $oneOf = [];
        $mapping = [];
        foreach ($type->members as $tag => $member) {
            $reference = '#/components/schemas/' . $this->component($member, $state);
            $oneOf[] = ['$ref' => $reference];
            $mapping[(string) $tag] = $reference;
        }
        $state->schemas[$name] = [
            'oneOf' => $oneOf,
            'discriminator' => ['propertyName' => $type->discriminator, 'mapping' => $mapping],
        ];

        return $name;
    }

    /**
     * Claims a component name for a class. Input and output descriptions of one class differ
     * in which properties are required, so they are separate components.
     *
     * @param class-string $class
     */
    private static function componentName(string $class, ?string $publicName, bool $output, GeneratorState $state): string
    {
        $separator = strrpos($class, '\\');
        $base = $publicName ?? ($separator === false ? $class : substr($class, $separator + 1));
        $name = $base;
        if (in_array($name, $state->componentNames, true)) {
            $other = $class . ($output ? ' input' : ' output');
            $name = isset($state->componentNames[$other]) && $state->componentNames[$other] === $base
                ? $base . ($output ? 'Output' : 'Input')
                : str_replace('\\', '.', $class) . ($output ? '.Output' : '');
        }
        $state->componentNames[$class . ($output ? ' output' : ' input')] = $name;

        return $name;
    }

    private function component(ObjectType $type, GeneratorState $state): string
    {
        $name = $state->componentNames[$type->class . ($type->output ? ' output' : ' input')] ?? null;
        if ($name !== null) {
            return $name;
        }
        $name = self::componentName($type->class, $type->name, $type->output, $state);

        $properties = [];
        $required = [];
        foreach ($type->properties as $property) {
            $schema = self::withConstraints($this->schema($property->type, $state), $property->constraints);
            if ($property->hasDefault) {
                $schema['default'] = $property->default;
            }
            if ($property->description !== '') {
                // OpenAPI 3.1 allows a description next to a reference.
                $schema['description'] = $property->description;
            }
            $properties[$property->name] = $schema === [] ? new stdClass() : $schema;
            if ($property->required) {
                $required[] = $property->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        if (!$type->additionalProperties) {
            $schema['additionalProperties'] = false;
        }
        $state->schemas[$name] = $schema;

        return $name;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<Constraint> $constraints
     * @return array<string, mixed>
     */
    private static function withConstraints(array $schema, array $constraints): array
    {
        $keywords = [];
        foreach ($constraints as $constraint) {
            $keywords = [...$keywords, ...$constraint->keywords()];
        }
        if ($keywords === []) {
            return $schema;
        }

        // Keywords next to a reference would be read as applying to the reference only in old tools.
        return isset($schema['$ref']) || isset($schema['anyOf']) ? ['allOf' => [$schema], ...$keywords] : [...$schema, ...$keywords];
    }

    private static function hasConstraints(Type $type): bool
    {
        return match (true) {
            $type instanceof ListType => self::hasConstraints($type->items),
            $type instanceof MapType => self::hasConstraints($type->values),
            $type instanceof ObjectType => array_any(
                $type->properties,
                static fn ($property): bool => $property->constraints !== [] || self::hasConstraints($property->type),
            ),
            $type instanceof UnionType => array_any($type->members, self::hasConstraints(...)),
            default => false,
        };
    }

    /**
     * "/files/{path...}" becomes "/files/{path}": OpenAPI paths cannot express multi-segment parameters.
     */
    private static function path(string $template): string
    {
        return preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\.\.\.\}/', '{$1}', $template) ?? $template;
    }

    /**
     * @return array<string, string>
     */
    private static function catchAllNote(string $template, string $name): array
    {
        return str_contains($template, '{' . $name . '...}')
            ? ['description' => 'Matches the rest of the path, including slashes.']
            : [];
    }
}
