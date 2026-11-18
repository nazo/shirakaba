# HTTP messages

`shirakaba/http-message` implements PSR-7 messages and PSR-17 factories and `provide`s
`psr/http-message-implementation` and `psr/http-factory-implementation`. Existing
implementations are good, but using one would add a third-party runtime dependency to every
application ([principles](01-principles.md)). Owning this code means owning its security, so
header validation and stream handling are tested in detail.

Any PSR-7 consumer accepts Shirakaba's messages, and Shirakaba accepts any implementation's
messages at its interfaces. Creating a server request from superglobals is not part of this
package; it belongs to the PHP-FPM runtime.

## URIs

- Parsing and normalisation delegate to PHP 8.5's URI extension. Characters that can never
  appear in a URI (spaces, control characters, non-ASCII bytes) are percent-encoded before
  parsing, as PSR-7 consumers such as HTTP clients expect; any other syntax error is rejected
  rather than repaired. Square brackets after the authority (`?tags[]=a`) are encoded too:
  RFC 3986 allows them only around an IPv6 host, but clients send them routinely.
- `getPath()` collapses multiple leading slashes into one, so a path used on its own cannot
  become a protocol-relative URL (`//evil.example`). The request target derives from it;
  `__toString()` keeps the path verbatim. Routers, signatures and proxies must state which of
  the two they use (the router uses `getPath()`).
- A URI without scheme and host must not have a colon in its first path segment (RFC 3986
  `path-noscheme`): `a:b` would read back with the scheme `a`. Setters that would produce this
  state throw; callers write `./a:b`.
- An empty user passed to `withUserInfo()` removes the user info, password included.

## Requests and headers

- A request target may not contain whitespace, C0 control characters or DEL. Only characters
  that are never valid are checked, not the grammar of the four target forms.
- Header names must be RFC 9110 tokens. Header values must consist of visible characters,
  spaces and tabs, which rejects CR, LF and NUL and therefore header injection; surrounding
  whitespace is trimmed. Integer values are accepted and converted to strings.
- Numeric header and attribute names are kept intact: arrays are never rebuilt with spread
  syntax, which would renumber their integer keys.
- Immutable `with*()` methods are marked `#[\NoDiscard]`. A call that changes nothing may
  return the same instance; this is an optimisation, not a guarantee.

## Streams

- Messages create their body stream lazily, so a message whose body is never touched opens no
  stream.
- `Stream` never closes its resource on destruction, because the resource may belong to the
  caller. If the caller closes a borrowed resource, the stream behaves as detached. The size is
  read on every call rather than cached, because the resource can be written through other
  handles.
- Files are opened with warnings suppressed and failures reported as exceptions whose messages
  are built from Shirakaba's own data. Neither a temporary error handler nor `error_get_last()`
  is used: both are process-wide and could mix up requests under coroutine runtimes.

## Uploaded files

- `UploadedFile::moveTo()` uses `move_uploaded_file()` for files registered by the SAPI and
  `rename()` otherwise, so runtimes that receive uploads outside the SAPI can hand over their
  temporary files. The file path must come from the runtime, never from user input.
- `UploadedFile` owns the stream it is given. A stream move copies to a temporary file next to
  the target (opened with mode `x`), completes short writes, treats an empty read before EOF as
  a failure, renames into place and then closes the source. A failed move removes the
  temporary file and leaves the target untouched. After a failed move has consumed part of a
  non-seekable stream, further moves are refused instead of storing the remainder.
- `ServerRequest` validates the uploaded-file tree but does not limit its size or depth;
  limits are enforced by the code that builds the tree, such as a multipart parser.

## Testing

Conformance is tested with `php-http/psr7-integration-tests` (a development dependency; its
tests that download a file are replaced by local equivalents, and tests run with
`allow_url_fopen=0`) plus Shirakaba's own tests for the edge cases above.

PHPStan does not yet understand `clone()` with property overrides: a readonly property that the
constructor only ever sets to null is inferred as always null, so such properties (the request
target) are not declared readonly.
