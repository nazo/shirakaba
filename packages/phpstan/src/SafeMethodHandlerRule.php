<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Handlers registered for safe methods (GET, HEAD) must not take a database Writer, nor a
 * class whose constructor takes one (design/13-static-analysis-and-agents.md and design/08-database.md). This looks one level deep, so it
 * is weaker than an effect proof: writes reached through other services are not seen.
 *
 * @implements Rule<MethodCall>
 */
final readonly class SafeMethodHandlerRule implements Rule
{
    /**
     * @var list<string>
     */
    private const array BUILDERS = ['Shirakaba\Core\AppBuilder', 'Shirakaba\Core\RouteGroup'];
    /**
     * @var array<string, string>
     */
    private const array SAFE_METHODS = ['get' => 'GET', 'head' => 'HEAD'];
    private const string WRITER = 'Shirakaba\Database\Writer';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }
        $method = self::SAFE_METHODS[strtolower($node->name->toString())] ?? null;
        $handler = $node->getArgs()[1] ?? null;
        if ($method === null || $handler === null || !$this->isBuilder($scope->getType($node->var))) {
            return [];
        }

        $type = $scope->getType($handler->value);
        if (!$type->isCallable()->yes()) {
            return [];
        }

        $writer = new ObjectType(self::WRITER);
        $path = $scope->getType($node->getArgs()[0]->value ?? $handler->value)->getConstantStrings();
        // Inside a group the path is relative to the group's prefix, which a rule cannot see.
        $inGroup = new ObjectType('Shirakaba\Core\RouteGroup')->isSuperTypeOf($scope->getType($node->var))->yes();
        $route = match (true) {
            count($path) !== 1 => $method,
            $inGroup && $path[0]->getValue() === '' => $method . ' <group root>',
            $inGroup => sprintf('%s %s (relative to its group)', $method, $path[0]->getValue()),
            default => sprintf('%s %s', $method, $path[0]->getValue()),
        };
        $errors = [];
        foreach ($type->getCallableParametersAcceptors($scope) as $acceptor) {
            foreach ($acceptor->getParameters() as $parameter) {
                $via = $this->writerIn($parameter->getType(), $writer, $scope);
                if ($via !== null) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'The route "%s" is a safe method but its handler takes %s ($%s); take a Reader instead (design/13-static-analysis-and-agents.md).',
                        $route,
                        $via,
                        $parameter->getName(),
                    ))->identifier('shirakaba.safeMethodWrites')->build();
                }
            }
        }

        return $errors;
    }

    /**
     * Describes how a parameter type gives write access, or null when it does not.
     */
    private function writerIn(\PHPStan\Type\Type $type, ObjectType $writer, Scope $scope): ?string
    {
        if ($writer->isSuperTypeOf($type)->yes()) {
            return 'a ' . self::WRITER;
        }
        foreach ($type->getObjectClassReflections() as $class) {
            if (!$class->hasConstructor()) {
                continue;
            }
            foreach ($class->getConstructor()->getOnlyVariant()->getParameters() as $dependency) {
                if ($writer->isSuperTypeOf($dependency->getType())->yes()) {
                    return sprintf('%s, which is built from a %s', $class->getName(), self::WRITER);
                }
            }
        }

        return null;
    }

    private function isBuilder(\PHPStan\Type\Type $type): bool
    {
        foreach (self::BUILDERS as $builder) {
            if (new ObjectType($builder)->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }
}
