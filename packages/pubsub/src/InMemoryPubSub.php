<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Writer;
use Shirakaba\PubSub\Internal\Channels;
use Shirakaba\PubSub\Internal\Receiver;
use SplQueue;

/**
 * Publish/subscribe within one process, for tests and single-process tools. Messages are
 * delivered when the writer's transaction commits, like PostgresPubSub, and receiving never
 * waits: an empty subscription returns null at once.
 */
final class InMemoryPubSub implements Publisher, Subscriber
{
    /** @var array<int, array{list<string>, SplQueue<Message>}> subscription id => channels, queue */
    private array $subscriptions = [];
    private int $nextId = 0;

    public function publish(Writer $db, string $channel, string $payload): void
    {
        [$channel] = Channels::check($channel);
        $db->afterCommit(function () use ($channel, $payload): void {
            foreach ($this->subscriptions as [$channels, $queue]) {
                if (in_array($channel, $channels, true)) {
                    $queue->enqueue(new Message($channel, $payload));
                }
            }
        });
    }

    /**
     * @param non-empty-list<string>|string $channels
     */
    public function subscribe(RequestScope $scope, string|array $channels): Subscription
    {
        $id = $this->nextId++;
        /** @var SplQueue<Message> $queue */
        $queue = new SplQueue();
        $this->subscriptions[$id] = [Channels::check($channels), $queue];
        $receiver = new class ($queue, fn () => $this->unsubscribe($id)) implements Receiver {
            /**
             * @param SplQueue<Message> $queue
             * @param \Closure(): void $unsubscribe
             */
            public function __construct(
                private readonly SplQueue $queue,
                private readonly \Closure $unsubscribe,
            ) {}

            public function receive(int $timeoutMilliseconds): ?Message
            {
                return $this->queue->isEmpty() ? null : $this->queue->dequeue();
            }

            public function close(): void
            {
                ($this->unsubscribe)();
            }
        };
        $subscription = new Subscription($receiver, $scope->cancellation());
        $scope->defer($subscription->close(...));

        return $subscription;
    }

    /**
     * How many subscriptions are open, to check that requests release theirs.
     */
    public function subscriptions(): int
    {
        return count($this->subscriptions);
    }

    private function unsubscribe(int $id): void
    {
        unset($this->subscriptions[$id]);
    }
}
