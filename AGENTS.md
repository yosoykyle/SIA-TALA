<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

<TALA_ORCHESTRATOR_ROUTER>
<!-- TALA Workflow Router: Governs agent execution boundaries, domain authorities, and lifecycle state across all environments -->

<authorities>
<!-- Orthogonal Domain Authorities: Each authority owns a distinct aspect of the project -->
1. Product Behavior & Policies: PRD modules (`00_Project_Documents/prd_modules/`).
2. User Interface & Role Surfaces: UI Surface Blueprint (`00_Project_Documents/ui_surface_blueprint.md`).
3. System Architecture & Boundaries: Architecture Specification (`00_Project_Documents/architecture_specification.md`).
4. Working Technical Reality: Passing test suites, live codebase, and active schema.
5. Task Scope & Acceptance: The active GitHub Issue (or direct user prompt for untracked work).
6. Workflow Governance & Boundaries: TALA Orchestrator Protocol (`00_Project_Documents/TALA-Orchestrator-Protocol.md`) and this Router.
7. Framework & Ecosystem Standards: The `<laravel-boost-guidelines>` block above.

*Conflict Resolution: If canonical documentation conflicts with proven, passing code, report the discrepancy before modifying working code. PRDs own product behavior; UI blueprint owns UI/roles; architecture owns deployment/integration boundaries; GitHub Issue owns task scope/status but never overrides product authority; GitHub Projects is a view, not a second task database.*
</authorities>

<workflow_boundaries>
<!-- Boundaries define permissions; a request may explicitly authorize multiple phases without separate chat turns. -->

Reuse a sufficient accepted Issue contract or direct task scope as the plan. Continue necessary inspection, implementation, verification, in-scope fixes, and review within the stated authorization. Commit, publication, Issue writes, isolation setup, merge, and deployment require their corresponding explicit authorization; multiple effects may be named in one request. Read-only requests remain read-only.

<boundary name="READ_ONLY" triggers="Plan #NN, Review, Audit, Diagnosis, Derive">
  <allowed>Read files, inspect git state, run read-only database queries, formulate implementation plans, draft issue contracts.</allowed>
  <prohibited>No file modifications, no code generation into workspace, no git commits, no branch creation, no git push, no issue creation or mutations.</prohibited>
</boundary>

<boundary name="LOCAL_EXECUTION" triggers="Implement, Fix, Change, Proceed">
  <allowed>Bounded edits to in-scope files, running tests, fixing in-scope failures, code formatting (Pint).</allowed>
  <prohibited>No git commit, no git push, no implicit branch creation, no PR creation, no deployment, no file edits outside in-scope target files. Necessary concurrent isolation setup requires explicit authorization as defined by the protocol.</prohibited>
</boundary>

<boundary name="COMPLETION_AND_PUBLISH" triggers="Complete #NN, Publish #NN, Commit">
  <allowed>
    - Complete: Finishes any remaining bounded implementation and verification under the accepted contract; requires an all-Verified criterion ledger before creating exactly ONE bounded local commit. Reuse completed work and valid evidence.
    - Publish (Solo Work on main): Pushes accepted commit range directly to origin/main after fresh, task-applicable local verification (including affected tests for code changes). Required CI must pass on the published commit before issue closure.
    - Publish (Concurrent Work): Pushes issue branch and opens PR containing "Closes #NN".
  </allowed>
  <prohibited>Never force-push, never merge PRs without separate explicit authorization, never deploy, never mutate unrelated issues.</prohibited>
</boundary>
</workflow_boundaries>

<github_projects_v2_automation>
<!-- Alignment with GitHub Projects v2 ("TALA Development") lifecycle statuses -->
- `Todo`: Newly created issues labeled `implementation` are automatically added to `Todo` by GitHub Project workflow automation.
- `In Progress`: Set by the agent under authorized lifecycle writes when tracked `LOCAL_EXECUTION` or `Complete #NN` begins, when a coordination cycle is active, or while an open PR is under review.
- `Done`: Set automatically by GitHub Project automation upon issue closure or linked PR merge.
- `Canceled`: Set by the agent only upon explicit, authorized abandonment or supersession with recorded rationale.
*Prohibition: Do not create custom statuses (e.g., 'In Review') or local shadow task queues. GitHub Projects is an issue view, not a second task database.*
</github_projects_v2_automation>

<task_modes>
- Tracked Work (`#NN`): Read named GitHub Issue. Transitions through Todo -> In Progress -> Done.
- Clear Direct Work (Untracked): Solo work operates directly on primary checkout (`main`) without creating extra branches, worktrees, or GitHub Issues. Follows the same 3 permission boundaries above.
- Task-applicable verification follows Protocol Section 7. For behavior-preserving changes, adequate affected tests satisfy verification without an extra test-only edit; add or update tests when changed behavior lacks coverage. This project-specific coverage rule qualifies the generic test-edit instruction in the generated Boost block.
- Compaction & Resumption: Re-anchor from recent messages, live git state, and issue comments before acting. Continue only within the authorized boundary.
</task_modes>

<human_gates>
Stop and ask confirmation ONLY when the existing authorization does not settle:
- A material product decision, authority conflict, or scope expansion.
- An external write or isolation setup outside the explicitly authorized effects.
- Destructive or hard-to-reverse operations.
- Adding new package dependencies, credentials, or cloud infrastructure costs.
- Structural architecture pivots that contradict established domain boundaries.
- Merging pull requests or deploying to production.

Do not ask again for an already explicit, applicable authorization. A material change to its scope or safety conditions requires a new decision. The protocol governs task-specific prerequisites, evidence reuse, and publication follow-through.
</human_gates>
</TALA_ORCHESTRATOR_ROUTER>
