# scope-missing: a query of a scoped entity has no scope

An entity with `#[RequiredScope(InTenant::class)]` can only be queried, updated or deleted
with that scope passed. Forgetting the tenant fails loudly instead of returning
or changing another tenant's rows. The check applies to `Entities::query()`, `get()`,
`find()`, writes by key, bulk updates and deletes, and relations loaded with `with()`.

## Fixes

**A. Pass the scope.** The value usually comes from the request (the signed-in user's
tenant):

```php
Entities::query(Task::class)->scoped(new InTenant($user->tenantId))->fetch($db);
Entities::get(Task::class, $id, $db, scopes: [new InTenant($user->tenantId)]);
```

**B. Switch it off on purpose.** Administrative code that must read across tenants names the
scope: `->withoutScope(InTenant::class)`. Trade-off: every such line is a place to review;
keep them few and obvious.

**C. Put the scope in a query class.** A `#[Db]` class built with the tenant (through
`Provide` or a constructor argument) applies it everywhere, so handlers cannot forget it.

## Common mistakes

- Making the scope a `#[DefaultScope]` to silence the error: default scopes take no request
  data and cannot express a tenant.
- Filtering with `->where('tenantId', ...)` instead of `->scoped(...)`: the check does not know
  about plain conditions, and related entities stay unfiltered.
