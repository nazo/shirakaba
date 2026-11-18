<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports reads of the wall clock outside a PSR-20 clock: time(), microtime(),
 * date() and friends without a timestamp, and DateTime objects for "now". Code that asks a
 * ClockInterface can be tested with a frozen clock and does not depend on php.ini's
 * date.timezone. hrtime() is allowed: durations need the monotonic clock.
 *
 * Checked files are configured with `parameters.shirakaba.clockPaths`; empty disables the rule.
 *
 * @implements Rule<Node>
 */
final readonly class NoAmbientClockRule implements Rule
{
    /**
     * Functions that read the current time when called with at most this many arguments.
     *
     * @var array<string, int>
     */
    private const array FUNCTIONS = [
        'time' => 0,
        'microtime' => 1,
        'date' => 1,
        'gmdate' => 1,
        'idate' => 1,
        'getdate' => 0,
        'localtime' => 0,
        'mktime' => 5,
        'gmmktime' => 5,
        'strtotime' => 1,
        'date_create' => 1,
        'date_create_immutable' => 1,
    ];

    /**
     * @param list<string> $patterns
     */
    public function __construct(
        private array $patterns = [],
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
        if (!$this->checks($scope)) {
            return [];
        }

        $read = match (true) {
            $node instanceof FuncCall => self::function($node),
            $node instanceof New_ => self::construction($node),
            default => null,
        };
        if ($read === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s reads the wall clock; ask a Psr\Clock\ClockInterface instead.', $read))
                ->identifier('shirakaba.ambientClock')
                ->build(),
        ];
    }

    private function checks(Scope $scope): bool
    {
        if ($this->patterns === []) {
            return false;
        }
        $class = $scope->getClassReflection();
        if ($class !== null && $class->implementsInterface('Psr\Clock\ClockInterface')) {
            return false;
        }
        $file = str_replace('\\', '/', $scope->getFile());

        return array_any($this->patterns, static fn (string $pattern): bool => preg_match($pattern, $file) === 1);
    }

    private static function function(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }
        $name = strtolower($call->name->getLast());
        $limit = self::FUNCTIONS[$name] ?? null;
        if ($limit === null || count($call->getArgs()) > $limit) {
            return null;
        }
        if (in_array($name, ['date_create', 'date_create_immutable'], true) && !self::isNow($call->getArgs()[0]->value ?? null)) {
            return null;
        }

        return $name . '()';
    }

    private static function construction(New_ $new): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }
        $class = ltrim($new->class->toString(), '\\');
        if (!in_array(strtolower($class), ['datetime', 'datetimeimmutable'], true)) {
            return null;
        }

        return self::isNow($new->getArgs()[0]->value ?? null) ? sprintf('new %s()', $class) : null;
    }

    /**
     * Whether a DateTime argument means the current time: absent, or a literal relative to now.
     */
    private static function isNow(?Node\Expr $argument): bool
    {
        if ($argument === null) {
            return true;
        }
        if (!$argument instanceof String_) {
            return false;
        }
        $value = strtolower(trim($argument->value));

        // An "@timestamp" or a full date is absolute; anything else is relative to now.
        return preg_match('/^@-?\d+$|^\d{4}-\d{2}-\d{2}/', $value) !== 1;
    }
}
