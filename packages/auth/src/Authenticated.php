<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use Attribute;
use ReflectionNamedType;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\MixedType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\ParameterExtractor;
use Shirakaba\Core\InvalidApplication;

/**
 * Requires an authenticated principal of the parameter's type:
 * `#[Authenticated] User $user`. Without one the request is a 401; a nullable parameter
 * makes authentication optional.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Authenticated implements ParameterExtractor, DescribesParameter
{
    public const string SOURCE = 'bearer';

    public function __construct(
        public string $realm = 'api',
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        [$class, $optional] = $this->type($parameter);
        $realm = $this->realm;

        return new AuthenticatedResolver($class, $optional, $realm);
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        [, $optional] = $this->type($parameter);

        return new ParameterDescription(self::SOURCE, $this->realm, $parameter->name(), new MixedType(), required: !$optional);
    }

    /**
     * @return array{class-string, bool}
     */
    private function type(HandlerParameter $parameter): array
    {
        $type = $parameter->reflection->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        if ($class === null || !(class_exists($class) || interface_exists($class))) {
            throw new InvalidApplication(sprintf('%s: #[Authenticated] needs a class or interface type.', $parameter->describe()));
        }

        return [$class, $type !== null && $type->allowsNull()];
    }
}
