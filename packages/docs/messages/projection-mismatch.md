# projection-mismatch: the projection does not match the requested relations

`into(Projection::class)` fills the projection's constructor ([database](../design/08-database.md)):

- a parameter typed with the entity class receives the entity;
- a parameter named like a requested relation receives it (a list for to-many relations);
- `<relation>Count` receives a `withCount()` count;
- any other parameter receives the entity property of the same name;
- `#[From('name')]` takes another name, and `#[Computed]` takes a value you pass in.

Every requested relation needs a parameter, and every parameter needs one of these sources.
The check runs before any query, and shirakaba/phpstan runs it too.

## Fixes

**A. Request the relation, or remove the parameter.** If the projection has `User $user`,
call `->with('user')`; if you did not mean to load it, drop the parameter.

**B. Add a parameter for each requested relation.** For nested paths, the parameter's class
is itself a projection: `#[Items(CommentView::class)] array $comments` for lists.

**C. Rename with `#[From]`.** `#[From('notifier')] UserView $user` receives the `notifier`
relation under another name.

**D. Mark values that do not come from the entity `#[Computed]`.** Pass them with the
`$computed` callback of `into()`, which receives all loaded entities (and, as a second list,
the related entities of nested projections with computed parameters) so one query serves the
page. Trade-off: the values are yours to compute and batch.

## Example

```php
final readonly class NoteView
{
    /** @param list<FileView> $files */
    public function __construct(
        public string $id,
        public string $text,
        public UserView $user,
        #[Items(FileView::class)] public array $files,
        #[Computed] public array $reactions = [],
    ) {}
}

$views = Entities::query(Note::class)
    ->with('user', 'files')
    ->into(NoteView::class, $db, static fn (array $notes): array => array_map(
        static fn (Note $note): array => ['reactions' => $counts[$note->id] ?? []],
        $notes,
    ));
```

## Common mistakes

- A parameter named after a column (`user_id`) rather than the property (`userId`).
- Requesting `comments.author` but declaring `array $comments` without `#[Items(...)]`: the
  nested relation needs a projection class to land in.
