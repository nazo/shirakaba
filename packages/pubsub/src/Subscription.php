<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use DateTimeImmutable;
use Generator;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\PubSub\Internal\Receiver;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Messages on the subscribed channels, from the moment of subscribing until close() or the
 * end of the request scope.
 */
final class Subscription
{
    private bool $closed = false;

    /**
     * @internal created by a Subscriber
     */
    public function __construct(
        private readonly Receiver $receiver,
        private readonly ?Cancellation $cancellation = null,
    ) {}

    /**
     * The next message, waiting up to $timeout seconds, or null when none arrived in time.
     */
    public function next(float $timeout = 0.0): ?Message
    {
        return $this->closed ? null : $this->receiver->receive(max(0, (int) round($timeout * 1000)));
    }

    /**
     * Messages as they arrive, with a null after each $heartbeat seconds of silence, ready
     * for an Sse response (which sends null as a keep-alive comment). It ends when the
     * subscription is closed, the request is cancelled, or $until passes; waiting stops at
     * $until rather than at the next heartbeat.
     *
     * @return Generator<int, Message|null>
     */
    public function messages(float $heartbeat = 15.0, ?DateTimeImmutable $until = null, ClockInterface $clock = new SystemClock()): Generator
    {
        while (!$this->closed && $this->cancellation?->isRequested() !== true) {
            $remaining = $until === null ? null : self::secondsUntil($until, $clock);
            if ($remaining !== null && $remaining <= 0) {
                return;
            }
            $message = $this->next($remaining === null ? $heartbeat : min($heartbeat, $remaining));
            if ($message === null && $until !== null && self::secondsUntil($until, $clock) <= 0) {
                // The deadline, not the heartbeat, ended the wait.
                return;
            }
            yield $message;
        }
    }

    private static function secondsUntil(DateTimeImmutable $until, ClockInterface $clock): float
    {
        return (float) $until->format('U.u') - (float) $clock->now()->format('U.u');
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->receiver->close();
        }
    }
}
