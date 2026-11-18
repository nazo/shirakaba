# Real-time events

Real-time features need a change written by one request to reach clients waiting for it.
Shirakaba offers Server-Sent Events served by PHP for small numbers of streams, development and
tests, and a Mercure hub for many streams. Both publish after the writing transaction commits.

## Typed Server-Sent Events

`Sse` responses (`shirakaba/streaming`) stream events from a generator, with the request scope kept
open until the stream ends ([runtimes and scopes](03-runtimes-and-scopes.md)).

- `#[SseResponse(['note' => NoteView::class, 'ping' => 'string'])]` on a handler declares its events,
  like `#[JsonResponse]`. Data types are `#[JsonDto]` or `#[JsonUnion]` classes, or `string`.
  `SseEvent::json($dto, 'note')` writes data with `Json`'s rules.
- Descriptions gain an `EventStreamType` (event name to data type).
- OpenAPI 3.1 cannot type the items of a stream. The document keeps `schema: {type: string}` for the
  stream and adds `x-itemSchema`, written exactly as OpenAPI 3.2's `itemSchema` for
  `text/event-stream`: an object with `event`, `data`, `id` and `retry`, `event` and `data` required,
  and a `oneOf` with one variant per event whose `event` is a `const` and whose `data` is a string with
  `contentMediaType: application/json` and `contentSchema` referencing the data type. When Shirakaba
  writes OpenAPI 3.2, the key becomes `itemSchema` and nothing else changes.
- The declaration is not checked against what the handler yields.

## Publish/subscribe

`shirakaba/pubsub` connects writers and streams with two interfaces.

- `Publisher::publish(Writer $db, $channel, $payload)` takes the writer, so delivery follows its
  transaction: subscribers receive the message after commit, never after a rollback.
- `Subscriber::subscribe(RequestScope $scope, $channels)` returns a `Subscription` that ends when the
  scope closes; for an `Sse` response, that is when the stream ends. `next($timeout)` waits for one
  message; `messages($heartbeat)` yields messages and a `null` after each silent interval, which `Sse`
  sends as a keep-alive, and stops when the request is cancelled.
- `PostgresPubSub` implements both on LISTEN/NOTIFY: `pg_notify()` on the writer (PostgreSQL delivers
  at commit by itself), `LISTEN` on the request's connection and `UNLISTEN *` when the scope closes.
  Both run through the connection, so they appear in the statement log. Payloads are small (under 8000
  bytes): send IDs and let the subscriber read the data with its own permissions.
- `InMemoryPubSub` implements both in one process, delivering through `afterCommit()`, for tests.
- Channel names for these implementations are identifiers (letters, digits and underscores, at most 63
  characters), so they can be quoted safely. Other brokers implement the same interfaces in their own
  packages, publishing from `afterCommit()` to keep the commit guarantee.

## Many streams through a hub

An `Sse` response holds a PHP worker, and with `PostgresPubSub` a database connection, for as long as
the stream is open. A handful of open streams can exhaust a small worker pool, worker mode does not
change that, and a closed browser is noticed only at the next write. A runtime that multiplexes
connections would change the execution model of the whole application, and one LISTEN connection per
process would still hold a worker per stream. Instead, the application publishes to a hub that holds
the browser connections. Mercure is an open protocol, and FrankenPHP has a hub built in.

- `MercurePublisher` implements `Publisher` for Mercure. It posts updates to the hub after the writer's
  transaction commits, so the commit guarantee holds, and takes any PSR-18 client. Channels become
  topics through a prefix; updates can be private. There is no PHP-side Mercure subscriber: the point
  is that PHP does not hold the connection.
- It follows Mercure 1.0. `MercureTokens` issues publisher and subscriber tokens as RFC 9068 access
  tokens (`typ: at+jwt`, `iss`, `aud`, `exp`, and grants in `authorization_details` entries with
  `actions` and `topics`), signed with HS256, with `iat` and `exp` from the PSR-20 clock and a random
  `jti`, and builds the subscriber cookie (`__Secure-mercure_access_token`, Secure, HttpOnly).
  Subscriber tokens therefore reach `EventSource` only as a same-site cookie; a hub on another site
  needs a fetch-based client that sends the `Authorization` header.
- `publishTo($db, $channels, $payload, private:, event:)` sends one update to several channels and
  chooses privacy and the SSE event per update.
- With a logger, hub failures after commit are logged and skipped; without one they are thrown.
  Callers that need retries publish through a queued job.
- The integration test runs against the hub built into FrankenPHP, configured with a Caddyfile.

## Typed hub events

Hub updates are a channel name and a string, described nowhere. Typed channels give them a contract
that clients can generate code from.

- A channel is a class declared with `#[EventChannel('board/{boardId}', private: true, summary:
  '...')]` and one `#[ChannelEvent('card.added', CardAdded::class)]` per event. Its constructor's
  promoted public parameters are exactly the template's variables (strings, integers or backed enums).
  Event classes are `#[JsonDto]`s that do not declare `type` themselves; event types are lower-case
  words joined by dots or underscores.
- The event kind is a `type` property inside the JSON payload, and updates are published without an
  SSE event name, so every update reaches `EventSource.onmessage`. Ordering data belongs in the
  payload.
- Topics are built from the template with each parameter percent-encoded, appended to the publisher's
  topic prefix; `MercureTokens` builds subscribe claims from channel instances the same way, so a token
  and the topic it must match cannot disagree. Mercure channel names are path segments of unreserved
  characters and percent-encodings, up to 1024 characters.
- `MercureEvents::publish($db, $channels, $event)` writes `{"type": ..., ...fields}`, publishes after
  commit, privately when the channel says so, and refuses an event the channel does not declare.
  Several instances of one channel class become one update with several topics.
- `EventChannels::describe(...)` turns channel classes into descriptions, and the OpenAPI generator
  writes them under a root `x-event-channels` extension:

  ```yaml
  x-event-channels:
    board:
      summary: Updates every participant of a board may see
      channel: 'board/{boardId}'
      parameters:
        boardId: {type: string}
      private: true
      content:
        application/json:
          schema: {$ref: '#/components/schemas/BoardEvent'}
  ```

  `BoardEvent` is a `oneOf` of the payload components with a `discriminator` on `type`, each member
  requiring `type` as a `const`.

The hub URL and the topic prefix stay deployment configuration, outside the document. A payload class
also used as an API response gets one component, so event payloads are best kept as their own classes.
