# Calling other servers

`shirakaba/http-client` is a PSR-18 client for calling servers you do not control: URLs from
other servers' documents, webhooks, object storage ([security](../design/09-security.md)).

```php
$client = new HttpClient(new HttpClientOptions(
    timeout: 10.0,
    maxResponseBytes: 1_000_000,
    prepare: static fn (RequestInterface $request): RequestInterface => $signer->sign($request),
));

$response = $client->in($scope)->sendRequest($factory->createRequest('GET', $actorUrl)
    ->withHeader('Accept', 'application/activity+json'));
```

What it guarantees:

- **No SSRF.** The client resolves the host itself, refuses it if any address is loopback,
  private, link-local (cloud metadata), or otherwise not globally routable, and connects only
  to the address it checked. Every redirect is checked the same way. Pass `allowAddress` to
  change the policy, for example to reach a local server in tests.
- **Bounded.** One `timeout` for the whole exchange, `connectTimeout` per connection,
  `maxResponseBytes` for the body, `maxRedirects` for redirects (0 returns them as they are).
- **Redirects like browsers**, dropping `Authorization` and `Cookie` when the origin changes.
- **Signing on every hop.** `prepare` runs on each request actually sent, after redirects, so
  an HTTP Signature always covers the request it travels with.
- **Tied to the request.** `in($scope)` stops the call when the request is cancelled or its
  deadline passes, and reports each request (`http.client.request`, without path or headers)
  to the request's observers.

Failures are PSR-18 exceptions: `RequestRefused` when nothing was sent (scheme, address,
redirect limit) and `NetworkError` otherwise, with `timedOut` and `tooLarge`.
