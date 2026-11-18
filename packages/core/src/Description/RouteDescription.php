<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

final readonly class RouteDescription
{
    /**
     * @param list<string> $templateParameters
     * @param list<ParameterDescription> $parameters
     * @param list<string> $undescribed PHP names of parameters whose extractor cannot describe itself
     * @param list<class-string> $responses declared return classes
     * @param list<ResponseDescription> $declaredResponses responses declared with attributes (design/06-json-and-openapi.md)
     */
    public function __construct(
        public string $method,
        public string $template,
        public ?string $name,
        public array $templateParameters,
        public array $parameters,
        public array $undescribed,
        public array $responses,
        public array $declaredResponses = [],
        /** False for routes marked undocumented(), which API descriptions leave out. */
        public bool $documented = true,
        /** The handler, as "Class::method", "function" or "closure at file:line". */
        public string $handler = '',
    ) {}

    /**
     * Names a handler for people and tools: "Class::method", "function", or "closure at
     * file:line".
     */
    public static function handlerName(\Closure $handler): string
    {
        $function = new \ReflectionFunction($handler);
        if (!str_contains($function->getName(), '{closure')) {
            $class = $function->getClosureScopeClass();

            return $class === null ? $function->getName() : $class->getName() . '::' . $function->getName();
        }

        return sprintf('closure at %s:%d', (string) $function->getFileName(), (int) $function->getStartLine());
    }
}
