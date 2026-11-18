<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Objects that live for the whole application (middleware, handlers, extractors and
 * resolvers) must not write to their own properties after construction: under a worker
 * runtime the next request, or a concurrent one, would see the value (design/03-runtimes-and-scopes.md).
 *
 * @implements Rule<Expr>
 */
final readonly class NoStateInAppScopedObjectRule implements Rule
{
    /**
     * @param list<class-string> $appScopedTypes
     */
    public function __construct(
        private PackageSource $source,
        private array $appScopedTypes = [
            'Psr\Http\Server\MiddlewareInterface',
            'Psr\Http\Server\RequestHandlerInterface',
            'Shirakaba\Core\Extraction\ParameterExtractor',
            'Shirakaba\Core\Extraction\ArgumentResolver',
        ],
    ) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Assign && !$node instanceof AssignOp && !$node instanceof AssignRef) {
            return [];
        }
        if (!$this->source->contains($scope) || $scope->getFunctionName() === '__construct') {
            return [];
        }

        $target = $node->var;
        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }
        if (!$target instanceof PropertyFetch || !$target->var instanceof Variable || $target->var->name !== 'this') {
            return [];
        }

        $class = $scope->getClassReflection();
        if ($class === null) {
            return [];
        }
        foreach ($this->appScopedTypes as $type) {
            if ($class->getName() === $type || $class->implementsInterface($type)) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        '%s lives for the whole application; writing to its properties after construction leaks state between requests (design/03-runtimes-and-scopes.md).',
                        $class->getDisplayName(),
                    ))->identifier('shirakaba.appScopedState')->build(),
                ];
            }
        }

        return [];
    }
}
