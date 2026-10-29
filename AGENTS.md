## Security Rules (Laravel API backend)

Assume the frontend is hostile. Every endpoint must be safe even if called
directly with curl by an attacker. Never rely on the frontend for security.

### Authentication and authorization
- Every route must be behind `auth:sanctum` (or the project's auth guard)
  unless it is explicitly public. State why when adding a public route.
- Authorize every action with Policies/Gates (`$this->authorize()` or
  `Gate::authorize()`). Role checks only in the UI do not count.
- Prevent IDOR: scope queries to the authenticated user
  (`$request->user()->orders()->findOrFail($id)`), never `Order::find($id)`
  with a client-supplied ID alone.
- Never trust client-sent `user_id`, `role`, `is_admin`, `price`, `total`,
  or status. Derive them server-side.

### Input validation and mass assignment
- Validate with Form Requests. Use `$request->validated()` only.
- NEVER use `$request->all()` or `Model::create($request->all())`.
- Every model defines `$fillable` explicitly. Never use `$guarded = []`.
- Validate file uploads: allowed `mimes`, `max` size, store on a private
  disk, generate the filename server-side.

### Database
- Use Eloquent or the query builder with bindings. No string-concatenated
  SQL. `DB::raw`, `whereRaw`, `orderByRaw` only with bound parameters, and
  never with request input in column or direction names (allowlist them).
- Wrap multi-step writes in `DB::transaction()`.

### Data exposure
- Return API Resources, never raw models. Hide sensitive fields
  (`$hidden` for password, tokens, internal flags).
- `APP_DEBUG=false` outside local. Never return stack traces or raw
  exception messages to clients.
- Do not log passwords, tokens, or full request bodies.

### Rate limiting and abuse
- Apply `throttle` to login, register, password reset, OTP, and any
  expensive endpoint.
- Paginate list endpoints with a max `per_page` cap.

### CORS, sessions, CSRF
- CORS `allowed_origins` lists exact frontend origins. Never `*` with
  `supports_credentials`.
- With Sanctum SPA cookie auth: keep CSRF protection on, and set
  `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, and `SameSite` deliberately.

### Secrets and config
- Never hardcode secrets or commit `.env`. Use `config()` and
  `env()` only inside config files.
- Never run `php artisan migrate:fresh`, `db:wipe`, or destructive
  commands against anything but the local database.

### Dependencies and commands
- Ask before running `composer require` or `composer update`. Verify the
  package exists, is maintained, and the name is exact.
- Never pipe remote scripts into a shell (`curl ... | bash`) without asking.
- Do not disable or loosen existing middleware, policies, or tests to make
  something pass.

### Workflow
- When adding or changing an endpoint, also add a feature test covering:
  unauthenticated access, another user's resource (expect 403/404), and
  invalid/extra input fields.
- After changes touching auth, routes, validation, or uploads, end with a
  "Security notes" section listing what changed and any risks.
- If a request requires breaking a rule above, stop and ask.

## Mentor Mode
I am new to Laravel and Next.js and this project is for learning.
- Explain WHY before showing code, in simple terms.
- Prefer small steps over large generated chunks. One concept at a time.
- When you write code, point out which lines matter for security and why.
- When there is a "bad but easy" way and a "right" way, show both and
  explain the difference.
- After finishing a task, give me one question to check my understanding.
