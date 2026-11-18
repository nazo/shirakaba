<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Shirakaba\Database\Writer;
use Shirakaba\PubSub\Internal\Channels;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publishes to a Mercure 1.0 hub (design/11-real-time-events.md), such as the one built into FrankenPHP,
 * so browsers subscribe to the hub instead of holding a PHP worker and a database connection
 * per stream:
 *
 *     $tokens = new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', $secret);
 *     $publisher = new MercurePublisher($client, $factory, $factory, 'http://localhost/.well-known/mercure', $tokens, 'https://example.com/streams/');
 *     $publisher->publish($db, 'notes', (string) $note->id);                      // public, after commit
 *     $publisher->publishTo($db, $followerChannels, $json, private: true);        // one update, many topics
 *     // Browser: new EventSource('/.well-known/mercure?match=' + encodeURIComponent('https://example.com/streams/notes'))
 *
 * Updates go out after the writer's transaction commits (afterCommit), never after a rollback.
 * When the hub refuses or cannot be reached, the error is thrown after the data is stored, or
 * with a logger, logged and skipped, for updates clients catch up on anyway.
 */
final readonly class MercurePublisher implements Publisher
{
    /**
     * @param string $hub the hub's publish URL, usually ending in /.well-known/mercure
     * @param MercureTokens|string $token issues a publisher token per update, or a fixed token
     *                                    whose grant covers the topics (and "*" for public updates)
     * @param string $topicPrefix prepended to channel names to form topics, such as
     *                            "https://example.com/streams/"
     * @param bool $private whether publish() sends private updates
     * @param LoggerInterface|null $logger report hub failures here instead of throwing
     */
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private string $hub,
        private MercureTokens|string $token,
        private string $topicPrefix = '',
        private bool $private = false,
        private ?LoggerInterface $logger = null,
    ) {}

    public function publish(Writer $db, string $channel, string $payload): void
    {
        $this->publishTo($db, [$channel], $payload, $this->private);
    }

    /**
     * Sends one update to several channels (repeated `topic` fields), for example a note to
     * every follower's private channel in one request.
     *
     * @param non-empty-list<string> $channels
     * @param string|null $event the SSE event name (`type`); "message" when null
     * @throws \InvalidArgumentException for an invalid channel name or event
     */
    public function publishTo(Writer $db, array $channels, string $payload, bool $private = false, ?string $event = null): void
    {
        $channels = Channels::checkTopics($channels);
        if ($event !== null && ($event === '' || $event === 'mercure' || preg_match('/[\r\n\0]/', $event) === 1)) {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be a Mercure event type.', $event));
        }
        $topics = array_map(fn (string $channel): string => $this->topicPrefix . $channel, $channels);
        $db->afterCommit(fn () => $this->send($topics, $payload, $private, $event));
    }

    /**
     * @param list<string> $topics
     * @throws RuntimeException when the hub refuses the update or cannot be reached, without a logger
     */
    private function send(array $topics, string $payload, bool $private, ?string $event): void
    {
        $fields = [];
        foreach ($topics as $topic) {
            $fields[] = 'topic=' . rawurlencode($topic);
        }
        $fields[] = 'data=' . rawurlencode($payload);
        if ($private) {
            $fields[] = 'private=on';
        }
        if ($event !== null) {
            $fields[] = 'type=' . rawurlencode($event);
        }
        $token = $this->token instanceof MercureTokens ? $this->token->publisher() : $this->token;
        $request = $this->requests->createRequest('POST', $this->hub)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream(implode('&', $fields)));
        try {
            $response = $this->client->sendRequest($request);
            $error = $response->getStatusCode() === 200 ? null : sprintf('The Mercure hub refused the update with status %d.', $response->getStatusCode());
        } catch (ClientExceptionInterface $e) {
            $error = sprintf('Publishing to the Mercure hub failed: %s', $e->getMessage());
        }
        if ($error === null) {
            return;
        }
        if ($this->logger === null) {
            throw new RuntimeException($error);
        }
        $this->logger->error($error, ['topics' => $topics]);
    }
}
