# Testing

`TestClient` calls the application in-process, without a server:

```php
$client = new TestClient(TaskApi::builder(TaskSchema::database($file))->build());

$response = $client->post('/tasks', '{"title":"Write"}', ['Content-Type' => 'application/json']);
self::assertSame(201, $response->getStatusCode());
self::assertSame('Write', ResponseJson::at($response, '/title'));

$client->form('POST', '/tasks/1/attachments', ['note' => 'spec'], ['file' => $uploadedFile]);
```

- `ResponseJson::at($response, '/items/0/title')` reads JSON with a JSON Pointer.
- Building with `build()` in tests checks every route and handler, which also protects
  production code that uses `build(lazy: true)`.
- Use a real database in tests, for example a temporary SQLite file; per-request
  connections cannot share an in-memory SQLite database.
- To catch drift between documentation and behaviour, validate response bodies against the
  generated OpenAPI schemas, as `examples/tasks/tests/TaskApiTest.php` does.
- To catch N+1 queries, add a `StatementLog` observer and bound the statements a request
  runs: `self::assertLessThanOrEqual(3, count($log->last()), $log->describe())`.
  `$log->repeated()` lists statements that ran more than once.
