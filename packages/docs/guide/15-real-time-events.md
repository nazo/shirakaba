# Real-time events

A browser keeps a Server-Sent Events stream open, and the server pushes an event whenever
something changes. `shirakaba/streaming` writes the stream and declares its events for OpenAPI;
`shirakaba/pubsub` carries changes from the requests that write them to the streams that wait
for them.

## Declaring and sending events

```php
#[SseResponse([
    'note' => NoteView::class,
    'noteDeleted' => NoteDeleted::class,
    'notification' => Notification::class,   // a #[JsonUnion]
])]
public function stream(RequestScope $scope, #[Db] Reader $db): Sse
{
    $subscription = $this->subscriber->subscribe($scope, ['notes', 'notifications']);

    return new Sse((function () use ($subscription, $db) {
        foreach ($subscription->messages(heartbeat: 15.0) as $message) {
            if ($message === null) {
                yield null;   // a keep-alive comment
                continue;
            }
            $view = $this->notes->visibleView((int) $message->payload, $db);
            if ($view !== null) {
                yield SseEvent::json($view, 'note', $message->payload);
            }
        }
    })());
}
```

`SseEvent::json()` writes the data with `Json`'s rules. OpenAPI describes the stream as a
string with an `x-itemSchema` listing each event and the type of its data, the shape
OpenAPI 3.2 uses for `itemSchema` ([real-time events](../design/11-real-time-events.md)), and the data types become components.

## Publishing changes

Publish with the writer that stores the change; subscribers receive the message when the
transaction commits, and never if it rolls back ([real-time events](../design/11-real-time-events.md)):

```php
$db->transaction(function (Writer $db) use ($note): void {
    $stored = Entities::insert($note, $db);
    $this->publisher->publish($db, 'notes', (string) $stored->id);
});
```

Send IDs, not data: each stream reads what its viewer may see, and PostgreSQL limits payloads
to under 8000 bytes. Channel names are letters, digits and underscores.

Wire `PostgresPubSub` (LISTEN/NOTIFY; open connections with `PDO::connect()`) as both
`Publisher` and `Subscriber`, and `InMemoryPubSub` in tests. A subscription ends with the
request scope, which for an `Sse` response is when the stream ends; `UNLISTEN` runs then.
`messages(heartbeat: 15.0, until: $openedAt->modify("+5 minutes"))` ends a stream at a
deadline without waiting for the next heartbeat.

## Capacity

Under PHP-FPM, the built-in server, FrankenPHP worker mode and RoadRunner alike, an open stream
holds a worker and a database connection until the client goes away, which is noticed only
when a write fails: four workers with four open streams answer nothing else. Swoole with
coroutine hooks serves other requests from the same worker while a stream waits, but each
stream still holds its database connection. Keep the heartbeat
short so closed streams are noticed, and size workers and connections for the number of open
streams.

For many streams, let a Mercure hub hold the connections ([real-time events](../design/11-real-time-events.md)). FrankenPHP has a
Mercure 1.0 hub built in; configure it in a Caddyfile, since its tokens name a trusted issuer:

```caddyfile
example.com {
	mercure {
		issuer https://example.com {
			publisher {
				jwt {env.MERCURE_KEY}
			}
			subscriber {
				jwt {env.MERCURE_KEY}
			}
		}
		resource_identifier https://example.com/.well-known/mercure
		anonymous    # public updates without a token
	}
	php_server
}
```

Issue tokens with `MercureTokens` and publish with `MercurePublisher` instead of
`PostgresPubSub`; it sends after commit like any `Publisher`:

```php
$tokens = new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', $key);
$publisher = new MercurePublisher($client, $factory, $factory, 'http://localhost/.well-known/mercure', $tokens, 'https://example.com/streams/', logger: $logger);

$publisher->publish($db, 'notes', (string) $note->id);                                   // public
$publisher->publishTo($db, $followerChannels, $json, private: true, event: 'note');      // one update, many topics
```

Browsers subscribe with `match` (Mercure 1.0 has no `topic` parameter):

```js
const events = new EventSource('/.well-known/mercure?match=' + encodeURIComponent('https://example.com/streams/users/42'));
```

Private updates reach only subscribers whose token grants the topic. Hubs read subscriber
tokens from the `Authorization` header or a cookie, never the URL, and `EventSource` cannot set
headers, so send the token as the cookie from a same-site response:

```php
$token = $tokens->subscriber('user-' . $user->id, ['https://example.com/streams/users/' . $user->id]);
return $response->withAddedHeader('Set-Cookie', $tokens->cookie($token));
```

With a logger, a hub that refuses or cannot be reached is logged and the request succeeds;
without one, the error surfaces after the data has been committed.

## Typed hub events

Declare what a hub channel carries, and the OpenAPI document describes it so clients can
generate types and decoders ([real-time events](../design/11-real-time-events.md)):

```php
#[EventChannel('board/{boardId}', private: true, summary: 'Updates every participant of a board may see')]
#[ChannelEvent('card.added', CardAdded::class)]
#[ChannelEvent('phase.changed', PhaseChanged::class)]
final readonly class BoardChannel
{
    public function __construct(public string $boardId) {}
}

#[JsonDto]
final readonly class CardAdded
{
    public function __construct(public int $seq, public CardView $card) {}
}
```

The constructor's parameters are the template's variables, exactly; they are strings,
integers or backed enums. Payloads are `#[JsonDto]`s without a `type` field, because the
channel writes it: a `card.added` event arrives as `{"type":"card.added","seq":3,"card":{...}}`.
Put ordering data such as `seq` in the payload; the hub's event id is its own.

```php
$events = new MercureEvents($publisher);                                       // application-scoped
$events->publish($db, new BoardChannel($board->id), new CardAdded($board->seq, $view));
$events->publish($db, [new BoardUserChannel($id, $a), new BoardUserChannel($id, $b)], $event);   // one update

$token = $tokens->channelSubscriber('user-' . $user->id, 'https://example.com/streams/', new BoardChannel($board->id));
```

Updates are published without an SSE event name, so `EventSource.onmessage` receives them all
and the client switches on `type`. Parameters are percent-encoded into the topic, the same way
for publishing and for tokens. Mercure channel names may be paths (letters, digits, `_`, `.`,
`~`, `-` and percent-encodings between slashes); `PostgresPubSub` keeps the stricter rule of
`LISTEN`.

Give the channels to the OpenAPI generator:

```php
new Generator($info, eventChannels: EventChannels::describe(BoardChannel::class, BoardUserChannel::class));
```

which writes a root `x-event-channels` extension: for each channel its `channel` template,
`parameters`, `private`, `summary` and `content`, whose schema is a component such as
`BoardEvent`, a `oneOf` of the payloads with a `discriminator` on `type`.
