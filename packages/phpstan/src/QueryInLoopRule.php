<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Reports database calls inside loops, the shape of an N+1 query: a call on a
 * Reader or Writer, or any call that receives one, in the body of a loop. Load in one query
 * instead (whereIn, with()). Opt-in with `parameters.shirakaba.reportQueriesInLoops`, since
 * batch jobs loop over queries on purpose.
 *
 * @implements Rule<Node\Stmt>
 */
final readonly class QueryInLoopRule implements Rule
{
    private const string READER = 'Shirakaba\Database\Reader';

    public function __construct(
        private bool $enabled = false,
    ) {}

    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->enabled || !($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_)) {
            return [];
        }
        $reader = new ObjectType(self::READER);
        $errors = [];
        foreach (new NodeFinder()->findInstanceOf($node->stmts, CallLike::class) as $call) {
            if ($call->isFirstClassCallable()) {
                continue;
            }
            $receivers = match (true) {
                $call instanceof MethodCall, $call instanceof NullsafeMethodCall => [$call->var],
                default => [],
            };
            $arguments = array_map(static fn (Node\Arg $arg): Node\Expr => $arg->value, $call->getArgs());
            foreach ([...$receivers, ...$arguments] as $expression) {
                // Types come from the scope before the loop, so variables defined inside it are skipped.
                if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && !$scope->hasVariableType($expression->name)->yes()) {
                    continue;
                }
                if ($reader->isSuperTypeOf($scope->getType($expression))->yes()) {
                    $errors[] = RuleErrorBuilder::message('A database query runs once per loop iteration (N+1); load the rows in one query with whereIn() or with().')
                        ->identifier('shirakaba.queryInLoop')
                        ->line($call->getStartLine())
                        ->build();
                    break;
                }
            }
            if ($call instanceof StaticCall) {
                continue;
            }
        }

        return $errors;
    }
}
