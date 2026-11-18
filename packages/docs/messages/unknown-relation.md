# unknown-relation: the entity declares no such relation

`with()`, `withWhere()`, `withCount()`, `whereHas()`, `whereDoesntHave()` and `#[From]` name
relations, and relations exist only when the entity class declares them ([database](../design/08-database.md)). Relations
are never inferred from property names or foreign keys, and never loaded on access.

## Fixes

**A. Declare the relation on the entity.**

```php
#[Table('notes')]
#[BelongsTo('user', User::class, 'userId')]                                   // notes.user_id
#[HasMany('replies', Note::class, 'replyId', orderBy: 'id')]                  // notes.reply_id
#[ManyToMany('files', DriveFile::class, through: 'note_files', localKey: 'note_id', foreignKey: 'file_id', orderBy: 'position')]
final readonly class Note { /* ... */ }
```

**B. Fix the name.** Names are the first argument of the attribute, case-sensitive; dotted
paths name a relation at each step (`reply.user` needs `reply` on Note and `user` on the
reply's entity). Run `app entities:show Note` to list them.

**C. Read it with a separate query.** When the data is not really a relation (a computed
value, another system), query it yourself and pass it in with `#[Computed]`.
Trade-off: you batch it yourself.

## Common mistakes

- Expecting `$note->user` to load lazily: entities are plain readonly objects; ask for
  relations with `with()` and read them through a projection.
- Using the column or property name (`userId`) instead of the relation name (`user`).
