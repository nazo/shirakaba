<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Attribute;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\MixedType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\ParameterExtractor;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\Internal\DbResolver;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Gives a handler the request's database access (design/08-database.md):
 *
 *     #[Db] Reader $db                // raw read access
 *     #[Db] Writer $db                // raw read and write access
 *     #[Db] TaskQueries $tasks        // a class built per request from a Reader or a Writer
 *
 * A class is accepted when its constructor takes exactly one Reader or Writer. Its other
 * parameters take application services from the request scope by their declared type,
 * placed there by the Provide middleware (design/08-database.md); a parameter with a default may be left
 * unprovided. Declaring read-only types documents, and lets PHPStan check, that a handler for
 * a safe method does not write. Needs DatabaseMiddleware.
 *
 * `#[Db('analytics')]` uses a database named in DatabaseMiddleware. In GET and
 * HEAD requests, readers come from the database's replica when it has one (design/08-database.md);
 * `#[Db(primary: true)]` reads from the primary, for data that must be current.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Db implements ParameterExtractor, DescribesParameter
{
    public function __construct(
        /** A database named in DatabaseMiddleware; the default one when null. */
        public ?string $database = null,
        /** Read from the primary even in GET and HEAD requests. */
        public bool $primary = false,
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        [$class, $writer, $access, $services] = $this->target($parameter);

        return new DbResolver($class, $writer, $access, $services, $this->database, $this->primary);
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        $this->target($parameter);

        return new ParameterDescription(ParameterDescription::INTERNAL, '', $parameter->name(), new MixedType(), required: true);
    }

    /**
     * @return array{class-string|null, bool, string, list<array{string, class-string, bool}>}
     *         the class to build (null for Reader/Writer), whether it needs a Writer, the
     *         parameter taking it, and the services: parameter, type, has a default
     */
    private function target(HandlerParameter $parameter): array
    {
        $type = $parameter->reflection->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;
        if ($name === Writer::class || $name === Reader::class) {
            return [null, $name === Writer::class, '', []];
        }

        $error = sprintf('%s: #[Db] needs Reader, Writer, or a class whose constructor takes exactly one Reader or Writer.', $parameter->describe()) . \Shirakaba\Core\Messages::see('db-parameter');
        if ($name === null || !class_exists($name)) {
            throw new InvalidApplication($error);
        }
        $class = new ReflectionClass($name);
        if (!$class->isInstantiable()) {
            throw new InvalidApplication($error);
        }
        $access = null;
        $writer = false;
        $services = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $constructorParameter) {
            $declared = $constructorParameter->getType();
            $typeName = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
            if ($typeName === Reader::class || $typeName === Writer::class) {
                if ($access !== null) {
                    throw new InvalidApplication($error);
                }
                $access = $constructorParameter->getName();
                $writer = $typeName === Writer::class;
                continue;
            }
            if ($typeName === null || (!class_exists($typeName) && !interface_exists($typeName))) {
                throw new InvalidApplication(sprintf(
                    '%s: %s::__construct() parameter $%s must be typed with a class or interface, so it can be provided from the request scope (Provide middleware).',
                    $parameter->describe(),
                    $name,
                    $constructorParameter->getName(),
                ));
            }
            $services[] = [$constructorParameter->getName(), $typeName, $constructorParameter->isDefaultValueAvailable()];
        }
        if ($access === null) {
            throw new InvalidApplication($error);
        }

        return [$name, $writer, $access, $services];
    }
}
