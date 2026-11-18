<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Closure;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\BodyExtractor;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\ParameterExtractor;
use Shirakaba\Core\Http\ResponseContext;
use Shirakaba\Core\Http\ResponseValue;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use UnexpectedValueException;

/**
 * Calls a handler with arguments produced by the resolvers planned at build time
 * (design/04-routing-and-handlers.md), then turns its result into a response. No reflection runs per request.
 *
 * @internal
 */
final readonly class HandlerInvoker implements RequestHandlerInterface
{
    /**
     * @param list<ArgumentResolver> $resolvers
     * @param list<int> $successStatuses declared 1xx-3xx statuses; empty when nothing is declared
     * @param int|null $body the position of the body parameter, resolved after the others
     */
    private function __construct(
        private Closure $handler,
        private array $resolvers,
        private ResponseContext $context,
        private array $successStatuses = [],
        private string $route = '',
        private ?int $body = null,
    ) {}

    /**
     * @param list<string> $routeParameters
     * @param bool $describe whether to produce parameter descriptions (AppBuilder::describe())
     * @throws InvalidApplication when the handler's signature cannot be served
     */
    public static function plan(Closure $handler, string $route, array $routeParameters, ResponseContext $context, bool $describe = false): HandlerPlan
    {
        $function = new ReflectionFunction($handler);
        $responses = self::checkReturnType($function->getReturnType(), $route);

        $resolvers = [];
        $descriptions = [];
        $undescribed = [];
        $body = null;
        foreach ($function->getParameters() as $index => $reflection) {
            $parameter = new HandlerParameter($reflection, $route, $routeParameters);
            if ($reflection->isVariadic() || $reflection->isPassedByReference()) {
                throw new InvalidApplication(sprintf('%s: variadic and by-reference parameters are not supported.', $parameter->describe()));
            }

            $attributes = $reflection->getAttributes(ParameterExtractor::class, ReflectionAttribute::IS_INSTANCEOF);
            if (count($attributes) > 1) {
                throw new InvalidApplication(sprintf('%s: only one extractor attribute is allowed.', $parameter->describe()));
            }

            $attribute = $attributes[0] ?? null;
            if ($attribute === null) {
                $resolvers[] = self::injected($parameter);
                continue;
            }

            $extractor = $attribute->newInstance();
            if ($extractor instanceof BodyExtractor) {
                if ($body !== null) {
                    throw new InvalidApplication(sprintf('%s: a handler may have only one body extractor.', $parameter->describe()));
                }
                $body = $index;
            }
            $resolvers[] = $extractor->plan($parameter);
            if (!$describe) {
                // build() needs no descriptions; describing would repeat the extractors' reflection.
                continue;
            }
            if ($extractor instanceof DescribesParameter) {
                $descriptions[] = $extractor->describe($parameter);
            } else {
                $undescribed[] = $reflection->getName();
            }
        }

        $declared = array_map(
            static fn (ReflectionAttribute $attribute): ResponseDescription => $attribute->newInstance()->responseDescription(),
            $function->getAttributes(ResponseDeclaration::class, ReflectionAttribute::IS_INSTANCEOF),
        );
        $successStatuses = array_values(array_unique(array_map(
            static fn (ResponseDescription $response): int => $response->status,
            array_filter($declared, static fn (ResponseDescription $response): bool => $response->status < 400),
        )));

        // With exactly one declared success status, response values use it by default.
        if (count($successStatuses) === 1) {
            $context = new ResponseContext($context->responses, $context->streams, $successStatuses[0]);
        }

        return new HandlerPlan(
            new self($handler, $resolvers, $context, $successStatuses, $route, $body),
            $descriptions,
            $undescribed,
            $responses,
            $describe ? $declared : [],
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = RequestScope::of($request);
        $exchange = new Exchange($request, RouteParameters::of($request), $scope);

        // The body is read last, so a request with an invalid path, query or header is
        // rejected without reading it.
        $arguments = [];
        $body = null;
        foreach ($this->resolvers as $index => $resolver) {
            if ($index === $this->body) {
                $body = $resolver;
                $arguments[$index] = null;
                continue;
            }
            $arguments[$index] = $resolver->resolve($exchange);
        }
        if ($body !== null && $this->body !== null) {
            $arguments[$this->body] = $body->resolve($exchange);
        }

        $result = ($this->handler)(...$arguments);
        $scope->observation()->handlerFinished();

        $response = match (true) {
            $result instanceof ResponseInterface => $result,
            $result instanceof ResponseValue => $result->toResponse($this->context),
            default => throw new UnexpectedValueException(sprintf(
                'A handler returned %s; handlers must return a ResponseInterface or a ResponseValue.',
                get_debug_type($result),
            )),
        };

        // Declared statuses are checked in development only (design/06-json-and-openapi.md).
        assert(
            $this->successStatuses === [] || in_array($response->getStatusCode(), $this->successStatuses, true),
            sprintf('Route "%s" returned status %d but declares %s.', $this->route, $response->getStatusCode(), implode(', ', $this->successStatuses)),
        );

        return $response;
    }

    /**
     * Parameters without an extractor attribute may only ask for the request or its scope.
     */
    private static function injected(HandlerParameter $parameter): ArgumentResolver
    {
        $type = $parameter->reflection->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        return match ($name) {
            ServerRequestInterface::class => new InjectedResolver(false),
            RequestScope::class => new InjectedResolver(true),
            default => throw new InvalidApplication(sprintf(
                '%s: add an extractor attribute such as #[Path], #[Query] or #[Header], or declare the type %s or %s.',
                $parameter->describe(),
                ServerRequestInterface::class,
                RequestScope::class,
            ) . \Shirakaba\Core\Messages::see('handler-parameter')),
        };
    }

    /**
     * @return list<class-string> the declared response classes
     */
    private static function checkReturnType(?ReflectionType $type, string $route): array
    {
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType && !self::containsIntersection($type) => $type->getTypes(),
            default => [],
        };

        $valid = $types !== [];
        $classes = [];
        foreach ($types as $member) {
            $name = $member instanceof ReflectionNamedType ? $member->getName() : '';
            if ($member->allowsNull() || !(is_a($name, ResponseInterface::class, true) || is_a($name, ResponseValue::class, true))) {
                $valid = false;
                continue;
            }
            $classes[] = $name;
        }

        if (!$valid) {
            throw new InvalidApplication(sprintf(
                'Route "%s": the handler must declare a return type of %s, %s, or subtypes of them.',
                $route,
                ResponseInterface::class,
                ResponseValue::class,
            ));
        }

        return $classes;
    }

    private static function containsIntersection(ReflectionUnionType $type): bool
    {
        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionIntersectionType) {
                return true;
            }
        }

        return false;
    }
}
