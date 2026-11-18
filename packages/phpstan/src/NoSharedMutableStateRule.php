<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids state that outlives a request in a long-running process (design/03-runtimes-and-scopes.md):
 * static properties, `global` and function-level `static` variables.
 *
 * @implements Rule<Node>
 */
final readonly class NoSharedMutableStateRule implements Rule
{
    public function __construct(
        private PackageSource $source,
    ) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->source->contains($scope)) {
            return [];
        }

        $message = match (true) {
            $node instanceof Property && $node->isStatic() => sprintf(
                'Static property $%s outlives the request in long-running processes; use a constant or request scope (design/03-runtimes-and-scopes.md).',
                implode(', $', array_map(static fn (Node\PropertyItem $item): string => $item->name->toString(), $node->props)),
            ),
            $node instanceof Global_ => 'The global statement shares state between requests (design/03-runtimes-and-scopes.md).',
            $node instanceof Static_ => 'Static variables outlive the request in long-running processes (design/03-runtimes-and-scopes.md).',
            default => null,
        };

        return $message === null ? [] : [
            RuleErrorBuilder::message($message)->identifier('shirakaba.sharedMutableState')->build(),
        ];
    }
}
