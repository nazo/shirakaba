Add sign-ups with a welcome mail sent in the background:

- `POST /users` accepts JSON `{"name": ..., "email": ...}` (name 1 to 100 characters, a valid
  e-mail address), stores the user and answers 201 with `{"id", "name", "email"}`;
- in the same transaction it queues a job; the job's handler, registered in
  `App\Background::register()`, records the mail as a row in the existing `sent_mails` table
  (`user_id`, `kind` = "welcome") instead of sending anything;
- the handler must be safe to run twice for the same user.

Use shirakaba/queue. Add a test and make sure `composer check` passes.
