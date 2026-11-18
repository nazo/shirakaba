Add notes to this application:

- a `notes` table with `id`, `author_id` (referencing `users`), `title` (up to 100
  characters) and `created_at`, created by a migration added to `App\Schema::migrations()`;
- a `Note` entity mapped to it, with a relation to its author, listed in `App\Schema::entities()`;
- `GET /notes` answering 200 with a JSON list of `{"id", "title", "author"}`, where `author` is
  the author's name, newest first.

Load the authors without one query per note. Add a test and make sure `composer check` passes.
