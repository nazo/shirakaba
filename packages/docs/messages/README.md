# Error pages

Errors that Shirakaba raises while building an application end with
`See vendor/shirakaba/docs/messages/<code>.md.` Each page explains the cause, the fixes and their
trade-offs, and shows a correct example ([static analysis and agents](../design/13-static-analysis-and-agents.md)).

| Code | Raised when |
| --- | --- |
| [handler-parameter](handler-parameter.md) | A handler parameter has no source |
| [db-parameter](db-parameter.md) | A `#[Db]` parameter is not a Reader, a Writer or a class built from one |
| [unknown-relation](unknown-relation.md) | `with()`, `whereHas()` or a projection names a relation the entity does not declare |
| [projection-mismatch](projection-mismatch.md) | A projection's parameters and the requested relations do not match |
| [scope-missing](scope-missing.md) | A query of an entity with `#[RequiredScope]` has no scope |
| [mutable-date-time](mutable-date-time.md) | A DTO field is `DateTime` or `DateTimeInterface` |
