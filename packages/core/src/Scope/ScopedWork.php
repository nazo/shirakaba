<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use Closure;
use Shirakaba\Core\Internal\SafeObservation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * Runs work outside HTTP, such as a queued job, with the lifetime and observation of a
 * request (design/03-runtimes-and-scopes.md and design/05-errors-and-observability.md): a fresh RequestScope whose cleanups run afterwards, and the
 * application's observers seeing it as a "request" with $kind as method and $name as route.
 *
 *     $error = ScopedWork::run($observers, 'JOB', 'mail.welcome', $cancellation,
 *         fn (RequestScope $scope) => $handler($job, $scope), ['traceparent' => $traceparent]);
 */
final readonly class ScopedWork
{
    /**
     * @param list<Observer> $observers
     * @param Closure(RequestScope): void $work
     * @param array<string, string> $propagation W3C propagation headers to continue a trace
     * @param Closure(?Throwable): int|null $status the status reported to observers; 200 on
     *                                       success and 500 on failure by default
     * @return Throwable|null what the work threw
     */
    public static function run(
        array $observers,
        string $kind,
        string $name,
        Cancellation $cancellation,
        Closure $work,
        array $propagation = [],
        ?Closure $status = null,
    ): ?Throwable {
        $observation = SafeObservation::begin($observers, new RequestSummary($kind, '1.1', $propagation));
        $observation->matched(new RouteSummary($kind, $name, $name));
        $scope = new RequestScope($cancellation, null, $observation);
        $error = null;
        try {
            $work($scope);
            $observation->handlerFinished();
        } catch (Throwable $e) {
            $error = $e;
            $observation->failed($e);
        } finally {
            $scope->close();
        }
        $observation->responseReady($status === null ? ($error === null ? 200 : 500) : $status($error));

        return $error;
    }
}
