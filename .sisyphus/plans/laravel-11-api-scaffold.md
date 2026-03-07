# Laravel 11 API Scaffold with Sanctum PAT

## TL;DR
> **Summary**: Bootstrap a greenfield Laravel 11 API-only backend with service-layer architecture, Sanctum PAT authentication, SQLite persistence, and automated tests/CI.
> **Deliverables**:
> - Laravel 11 project scaffold configured for API use
> - Service classes injected directly into controllers (no repository layer)
> - Sanctum PAT auth endpoints under `/api/v1`
> - SQLite migrations and baseline test coverage
> - GitHub Actions workflow that runs tests
> **Effort**: Medium
> **Parallel**: YES - 3 waves
> **Critical Path**: Task 1 -> Task 2 -> Task 4 -> Task 6 -> Task 9

## Context
### Original Request
Create Laravel 11.x scaffold for API services, use service pattern with direct service DI in controllers, avoid repository interfaces, implement basic Sanctum auth, and use SQLite.

### Interview Summary
- Repository is greenfield (only `.git` and `.sisyphus` exist).
- Auth model locked to Sanctum personal access tokens (PAT), not SPA cookie auth.
- Route strategy locked to path versioning: `/api/v1`.
- Test strategy locked to tests-after implementation plus CI workflow.

### Metis Review (gaps addressed)
- Added guardrails to prevent scope creep (no RBAC, no password reset, no email verification, no deployment infra).
- Fixed decision defaults for unresolved items:
  - Logout revokes current token only.
  - Token abilities deferred (single default ability).
  - Standardized JSON envelope will be implemented for auth responses.
- Included edge-case QA requirements for invalid credentials, duplicate registration, and unauthorized access.

## Work Objectives
### Core Objective
Deliver a runnable Laravel 11 API project with production-style structure for services and authentication while keeping MVP scope intentionally narrow.

### Deliverables
- Laravel 11 project initialized and configured for API-only behavior.
- `App\\Services\\AuthService` and supporting service-layer conventions documented in code structure.
- Auth endpoints: `POST /api/v1/register`, `POST /api/v1/login`, `GET /api/v1/me`, `POST /api/v1/logout`.
- SQLite configured for local and test environments.
- Feature tests for happy/failure auth paths.
- CI workflow executing migrations and test suite.

### Definition of Done (verifiable conditions with commands)
- `php artisan --version` returns Laravel 11.x.
- `php artisan route:list` includes all four `/api/v1` auth endpoints.
- `php artisan migrate:fresh --force` succeeds with Sanctum token table present.
- `php artisan test` passes locally and in CI.
- `test -f .github/workflows/ci.yml` returns success.

### Must Have
- Laravel 11.x scaffold.
- Service pattern with direct DI in controllers.
- No repository classes/interfaces.
- Sanctum PAT auth with token issuance and revocation.
- Uniform JSON response envelope for auth success/error payloads.
- SQLite as configured DB engine.

### Must NOT Have (guardrails, AI slop patterns, scope boundaries)
- No repository pattern artifacts (`Repository`, `RepositoryInterface`, bindings).
- No SPA cookie/stateful Sanctum flow.
- No role/permission system, password reset, email verification.
- No frontend/UI scaffolding.
- No Docker/deployment/infra hardening in this plan.

## Verification Strategy
> ZERO HUMAN INTERVENTION - all verification is agent-executed.
- Test decision: tests-after + PHPUnit (Laravel default) with Feature-first coverage.
- QA policy: Every implementation task contains happy + failure scenarios.
- Evidence: `.sisyphus/evidence/task-{N}-{slug}.{ext}`

## Execution Strategy
### Parallel Execution Waves
> Target: 5-8 tasks per wave. Shared dependencies extracted to Wave 1.

Wave 1: Foundation bootstrap and architecture constraints (Tasks 1-3)
Wave 2: Auth feature implementation and route wiring (Tasks 4-7)
Wave 3: Test/CI hardening and anti-pattern compliance checks (Tasks 8-10)

### Dependency Matrix (full, all tasks)
- Task 1 blocks Tasks 2-10
- Task 2 blocks Tasks 4-9
- Task 3 blocks Tasks 4-7
- Task 4 blocks Tasks 5-7
- Task 5 blocks Tasks 6-8
- Task 6 blocks Task 8
- Task 7 blocks Task 8
- Task 8 blocks Task 9
- Task 9 blocks Task 10

### Agent Dispatch Summary (wave -> task count -> categories)
- Wave 1 -> 3 tasks -> `quick`, `unspecified-low`
- Wave 2 -> 4 tasks -> `unspecified-high`, `deep`
- Wave 3 -> 3 tasks -> `quick`, `unspecified-high`

## TODOs
> Implementation + Test = ONE task. Never separate.
> EVERY task includes agent profile, parallelization, references, acceptance criteria, and QA scenarios.

- [x] 1. Bootstrap Laravel 11 Project in Repository Root

  **What to do**: Initialize Laravel 11.x in current repository root, ensure `.env` and app key are set, and confirm app boots via Artisan.
  **Must NOT do**: Do not scaffold frontend stacks (Breeze/Jetstream/Inertia) and do not introduce non-Laravel starter templates.

  **Recommended Agent Profile**:
  - Category: `quick` - Reason: deterministic project bootstrap commands.
  - Skills: `[]` - no specialized skill required.
  - Omitted: `git-master` - not needed for implementation itself.

  **Parallelization**: Can Parallel: NO | Wave 1 | Blocks: 2,3,4,5,6,7,8,9,10 | Blocked By: none

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/installation` - official Laravel 11 install constraints.
  - External: `https://laravel.com/docs/11.x/configuration#the-environment-file` - environment setup requirements.
  - Context: `.sisyphus/drafts/laravel-11-api-scaffold.md` - confirmed project goals and guardrails.

  **Acceptance Criteria** (agent-executable only):
  - [x] `php artisan --version` exits 0 and contains `Laravel Framework 11`.
  - [x] `php artisan key:generate --show` exits 0.
  - [x] `test -f composer.json && test -f artisan` exits 0.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path bootstrap
    Tool: Bash
    Steps: run `php artisan --version`; run `php artisan about`
    Expected: both commands exit 0 and print framework metadata.
    Evidence: .sisyphus/evidence/task-1-bootstrap.txt

  Scenario: Failure path missing PHP extension
    Tool: Bash
    Steps: run `php -m | grep -E "pdo_sqlite|sqlite3"`
    Expected: command shows required sqlite extensions; if missing, output explicit blocker note.
    Evidence: .sisyphus/evidence/task-1-bootstrap-error.txt
  ```

  **Commit**: YES | Message: `chore(bootstrap): initialize laravel 11 api scaffold` | Files: `composer.json`, `artisan`, `app/**`, `config/**`, `routes/**`

- [x] 2. Configure SQLite for Local and Test Environments

  **What to do**: Configure `.env.example`, `.env.testing`, and Laravel DB config for SQLite-first development and test execution, including database file creation and migration readiness.
  **Must NOT do**: Do not introduce MySQL/PostgreSQL-specific config as default runtime path.

  **Recommended Agent Profile**:
  - Category: `quick` - Reason: focused environment/config edits.
  - Skills: `[]` - no special integration skill required.
  - Omitted: `playwright` - no browser workflow needed.

  **Parallelization**: Can Parallel: NO | Wave 1 | Blocks: 4,5,6,8,9 | Blocked By: 1

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/database#sqlite-configuration` - authoritative SQLite setup.
  - External: `https://laravel.com/docs/11.x/testing#the-env-testing-environment-file` - `.env.testing` behavior.
  - Context: `.sisyphus/drafts/laravel-11-api-scaffold.md` - DB decision is SQLite.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `php artisan config:clear && php artisan config:cache` exits 0.
  - [ ] `php artisan migrate:status` exits 0 using SQLite config.
  - [ ] `test -f database/database.sqlite` exits 0.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path sqlite migration readiness
    Tool: Bash
    Steps: run `php artisan migrate:fresh --force`
    Expected: exits 0 and recreates schema without DB connection errors.
    Evidence: .sisyphus/evidence/task-2-sqlite.txt

  Scenario: Failure path bad DB path
    Tool: Bash
    Steps: temporarily point DB_DATABASE to non-existent sqlite file in subshell and run `php artisan migrate:status`
    Expected: exits non-zero with deterministic sqlite file/path error.
    Evidence: .sisyphus/evidence/task-2-sqlite-error.txt
  ```

  **Commit**: YES | Message: `chore(config): set sqlite defaults for local and test` | Files: `.env.example`, `.env.testing`, `config/database.php`

- [x] 3. Establish Service-Layer Architecture Guardrails

  **What to do**: Create service-layer conventions (`App\\Services`) and controller/service boundary rules, including dependency injection pattern and explicit no-repository policy.
  **Must NOT do**: Do not create repository interfaces, repository classes, or repository container bindings.

  **Recommended Agent Profile**:
  - Category: `unspecified-low` - Reason: architectural structure and conventions.
  - Skills: `[]` - conventions derive from Laravel core container behavior.
  - Omitted: `librarian` - external research already settled.

  **Parallelization**: Can Parallel: YES | Wave 1 | Blocks: 4,5,6,7 | Blocked By: 1

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/container#automatic-injection` - direct DI into controllers.
  - External: `https://laravel.com/docs/11.x/controllers` - controller responsibility boundaries.
  - Context: `.sisyphus/drafts/laravel-11-api-scaffold.md` - service pattern requirement and repo exclusion.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `test -d app/Services` exits 0.
  - [ ] `grep -R "RepositoryInterface\|class .*Repository" app bootstrap config routes` returns no matches.
  - [ ] `php artisan optimize:clear` exits 0 after structure changes.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path DI conventions
    Tool: Bash
    Steps: create/check one service class and one controller constructor-injected dependency; run `php artisan route:list`
    Expected: route listing succeeds and app container resolves controller dependencies.
    Evidence: .sisyphus/evidence/task-3-service-pattern.txt

  Scenario: Failure path repository pattern regression
    Tool: Bash
    Steps: run static search `grep -R "Repository" app`
    Expected: zero matches; any match fails task with explicit remediation note.
    Evidence: .sisyphus/evidence/task-3-service-pattern-error.txt
  ```

  **Commit**: YES | Message: `chore(architecture): define service-layer conventions` | Files: `app/Services/**`, `app/Http/Controllers/**`

- [x] 4. Install and Configure Sanctum for PAT Authentication

  **What to do**: Install Sanctum, publish/migrate required resources, configure API guard usage for token auth, and ensure middleware/auth stack supports PAT routes.
  **Must NOT do**: Do not enable SPA stateful cookie flow or frontend middleware.

  **Recommended Agent Profile**:
  - Category: `unspecified-high` - Reason: security-sensitive framework integration.
  - Skills: `[]` - Laravel/Sanctum docs sufficient.
  - Omitted: `playwright` - API-only validation.

  **Parallelization**: Can Parallel: NO | Wave 2 | Blocks: 5,6,7,8 | Blocked By: 1,2,3

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/sanctum` - official Sanctum PAT implementation.
  - External: `https://laravel.com/docs/11.x/authentication` - guard behavior and API auth usage.
  - Context: `.sisyphus/drafts/laravel-11-api-scaffold.md` - PAT-only decision.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `php artisan migrate:fresh --force` creates `personal_access_tokens` table.
  - [ ] `php artisan tinker --execute="echo class_exists('Laravel\\Sanctum\\Sanctum') ? 'ok' : 'fail';"` prints `ok`.
  - [ ] `php artisan route:list` runs without middleware/guard resolution errors.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path sanctum setup
    Tool: Bash
    Steps: run package install/publish/migrate commands; execute route list.
    Expected: no errors; sanctum migration applied.
    Evidence: .sisyphus/evidence/task-4-sanctum.txt

  Scenario: Failure path unauthorized token access
    Tool: Bash
    Steps: call protected endpoint without bearer token using curl.
    Expected: HTTP 401 JSON response.
    Evidence: .sisyphus/evidence/task-4-sanctum-error.txt
  ```

  **Commit**: YES | Message: `feat(auth): install sanctum pat foundation` | Files: `composer.json`, `config/sanctum.php`, `database/migrations/**`

- [x] 5. Implement AuthService Business Logic (Register/Login/Me/Logout)

  **What to do**: Create `AuthService` handling registration, credential authentication, token creation, current-user retrieval, and current-token revocation.
  **Must NOT do**: Do not place business logic directly in controllers; do not revoke all user tokens on logout.

  **Recommended Agent Profile**:
  - Category: `deep` - Reason: centralizes auth flows and error semantics.
  - Skills: `[]` - internal implementation based on Laravel auth APIs.
  - Omitted: `git-master` - no history operations needed.

  **Parallelization**: Can Parallel: NO | Wave 2 | Blocks: 6,8 | Blocked By: 2,3,4

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/authentication#authenticating-users` - login/credential flow.
  - External: `https://laravel.com/docs/11.x/sanctum#issuing-api-tokens` - token issuance APIs.
  - External: `https://laravel.com/docs/11.x/validation` - validation expectations for register/login input.

  **Acceptance Criteria** (agent-executable only):
  - [ ] Auth service methods exist for register/login/me/logout with typed parameters and return payloads.
  - [ ] Logout path deletes only `currentAccessToken()`.
  - [ ] `php -l app/Services/AuthService.php` exits 0.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path token issuance and revocation logic
    Tool: Bash
    Steps: run feature call sequence register->login->me->logout using API tests or curl.
    Expected: token issued on auth success, `me` returns user, token invalid after logout.
    Evidence: .sisyphus/evidence/task-5-auth-service.txt

  Scenario: Failure path invalid credentials
    Tool: Bash
    Steps: submit wrong password to login flow.
    Expected: HTTP 401 with deterministic JSON error and no token generated.
    Evidence: .sisyphus/evidence/task-5-auth-service-error.txt
  ```

  **Commit**: YES | Message: `feat(auth): add auth service for sanctum pat flows` | Files: `app/Services/AuthService.php`, `app/Models/User.php`

- [x] 6. Add API v1 Auth Controllers, Form Requests, and JSON Envelope

  **What to do**: Implement controller endpoints for auth routes, inject `AuthService` directly via DI, validate input with Form Requests, and return uniform JSON shape (`message`, `data`, `errors`).
  **Must NOT do**: Do not return inconsistent response payload structures; do not call Eloquent auth logic directly from controller methods.

  **Recommended Agent Profile**:
  - Category: `unspecified-high` - Reason: endpoint contract + validation + integration.
  - Skills: `[]` - standard Laravel HTTP layer patterns.
  - Omitted: `frontend-ui-ux` - no UI scope.

  **Parallelization**: Can Parallel: YES | Wave 2 | Blocks: 8 | Blocked By: 2,3,4,5

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/controllers` - controller conventions.
  - External: `https://laravel.com/docs/11.x/validation#form-request-validation` - Form Request usage.
  - External: `https://laravel.com/docs/11.x/responses#json-responses` - JSON response behavior.

  **Acceptance Criteria** (agent-executable only):
  - [ ] Endpoints implemented: `POST /api/v1/register`, `POST /api/v1/login`, `GET /api/v1/me`, `POST /api/v1/logout`.
  - [ ] Controller constructor/method DI uses service class directly.
  - [ ] Validation failures return HTTP 422 with `errors` object.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path endpoint contract
    Tool: Bash
    Steps: call register/login/me/logout endpoints with valid payloads.
    Expected: responses include `message`, `data`, `errors` keys with expected values and status codes.
    Evidence: .sisyphus/evidence/task-6-auth-controller.txt

  Scenario: Failure path duplicate email on register
    Tool: Bash
    Steps: register same email twice.
    Expected: second request returns HTTP 422 with email validation error.
    Evidence: .sisyphus/evidence/task-6-auth-controller-error.txt
  ```

  **Commit**: YES | Message: `feat(api): add v1 auth controllers and requests` | Files: `app/Http/Controllers/Api/V1/**`, `app/Http/Requests/Auth/**`

- [x] 7. Wire Versioned Routes and Auth Middleware Policy

  **What to do**: Register auth endpoints in `routes/api.php` under `/api/v1`, apply `auth:sanctum` middleware to `me` and `logout`, keep `register` and `login` public.
  **Must NOT do**: Do not expose `me/logout` without auth middleware and do not mix unversioned route duplicates.

  **Recommended Agent Profile**:
  - Category: `quick` - Reason: deterministic route/middleware wiring.
  - Skills: `[]` - straightforward framework config.
  - Omitted: `deep` - low algorithmic complexity.

  **Parallelization**: Can Parallel: YES | Wave 2 | Blocks: 8 | Blocked By: 3,4

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/routing#route-groups` - versioned route grouping.
  - External: `https://laravel.com/docs/11.x/sanctum#protecting-routes` - Sanctum middleware usage.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `php artisan route:list | grep -E "api/v1/(register|login|me|logout)"` returns 4 expected routes.
  - [ ] Route list shows `auth:sanctum` middleware on `me` and `logout` only.
  - [ ] No duplicate `/api/*` auth routes outside `/v1` group.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path middleware gating
    Tool: Bash
    Steps: call login and capture bearer token; call me with token.
    Expected: login succeeds; me returns HTTP 200 when token present.
    Evidence: .sisyphus/evidence/task-7-routes.txt

  Scenario: Failure path missing token
    Tool: Bash
    Steps: call `/api/v1/me` and `/api/v1/logout` without Authorization header.
    Expected: both return HTTP 401 JSON responses.
    Evidence: .sisyphus/evidence/task-7-routes-error.txt
  ```

  **Commit**: YES | Message: `feat(routing): enforce v1 sanctum auth route policy` | Files: `routes/api.php`

- [x] 8. Build Feature Tests for Auth Happy/Failure Paths

  **What to do**: Implement Feature tests covering register/login/me/logout success flows and required failure behaviors (duplicate email, invalid credentials, unauthorized access, revoked token usage).
  **Must NOT do**: Do not write only happy-path tests; do not rely on manual verification.

  **Recommended Agent Profile**:
  - Category: `unspecified-high` - Reason: broad behavior validation and edge cases.
  - Skills: `[]` - Laravel test framework defaults.
  - Omitted: `playwright` - API test coverage handled via PHPUnit feature tests.

  **Parallelization**: Can Parallel: NO | Wave 3 | Blocks: 9 | Blocked By: 2,4,5,6,7

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/http-tests` - HTTP/JSON test patterns.
  - External: `https://laravel.com/docs/11.x/testing#running-tests` - execution practices.
  - External: `https://laravel.com/docs/11.x/sanctum#testing` - Sanctum testing patterns.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `php artisan test --filter=Auth` exits 0.
  - [ ] Tests assert statuses: 200/201 for success, 401 for unauthorized/invalid credentials, 422 for validation failures.
  - [ ] Revoked token access test proves post-logout denial.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path auth lifecycle
    Tool: Bash
    Steps: run `php artisan test --filter=AuthLifecycle`
    Expected: lifecycle test passes for register->login->me->logout->me(401).
    Evidence: .sisyphus/evidence/task-8-tests.txt

  Scenario: Failure path invalid login and duplicate registration
    Tool: Bash
    Steps: run `php artisan test --filter=AuthFailureCases`
    Expected: invalid login returns 401 and duplicate email returns 422 assertions pass.
    Evidence: .sisyphus/evidence/task-8-tests-error.txt
  ```

  **Commit**: YES | Message: `test(auth): cover sanctum pat happy and failure flows` | Files: `tests/Feature/Auth/**`

- [x] 9. Add GitHub Actions CI for SQLite Migrate + Test Pipeline

  **What to do**: Create CI workflow that installs PHP dependencies, prepares SQLite test database, runs migrations, and executes test suite on push/PR.
  **Must NOT do**: Do not enable matrix complexity beyond agreed baseline (single supported PHP version for MVP).

  **Recommended Agent Profile**:
  - Category: `quick` - Reason: deterministic workflow setup.
  - Skills: `[]` - standard GitHub Actions YAML.
  - Omitted: `deep` - no complex architecture decision left.

  **Parallelization**: Can Parallel: NO | Wave 3 | Blocks: 10 | Blocked By: 2,8

  **References** (executor has NO interview context - be exhaustive):
  - External: `https://laravel.com/docs/11.x/testing#running-tests-in-ci` - CI baseline for Laravel tests.
  - External: `https://docs.github.com/actions` - workflow syntax and best practices.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `test -f .github/workflows/ci.yml` exits 0.
  - [ ] `grep -q "php artisan migrate" .github/workflows/ci.yml` exits 0.
  - [ ] `grep -q "php artisan test" .github/workflows/ci.yml` exits 0.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path ci workflow lint
    Tool: Bash
    Steps: validate workflow exists and includes dependency install, sqlite prep, migrate, test steps.
    Expected: all required commands present exactly once.
    Evidence: .sisyphus/evidence/task-9-ci.txt

  Scenario: Failure path missing migrate step
    Tool: Bash
    Steps: run grep assertion script expecting migrate command presence.
    Expected: script fails if migrate step absent, preventing incomplete CI merge.
    Evidence: .sisyphus/evidence/task-9-ci-error.txt
  ```

  **Commit**: YES | Message: `ci(test): add sqlite migrate and test workflow` | Files: `.github/workflows/ci.yml`

- [x] 10. Enforce Scope Compliance and Final Quality Gate

  **What to do**: Run final static and runtime checks to ensure no repository pattern artifacts, all routes/flows work, and agreed MVP boundaries remain intact.
  **Must NOT do**: Do not add late-stage feature expansions (RBAC, reset passwords, UI, deployment files).

  **Recommended Agent Profile**:
  - Category: `unspecified-high` - Reason: comprehensive compliance audit.
  - Skills: `[]` - relies on command-level verification.
  - Omitted: `frontend-ui-ux` - no frontend deliverables.

  **Parallelization**: Can Parallel: NO | Wave 3 | Blocks: none | Blocked By: 1,2,3,4,5,6,7,8,9

  **References** (executor has NO interview context - be exhaustive):
  - Context: `.sisyphus/drafts/laravel-11-api-scaffold.md` - original scope and explicit exclusions.
  - Plan: `.sisyphus/plans/laravel-11-api-scaffold.md` - final checklist to satisfy.
  - External: `https://laravel.com/docs/11.x/artisan` - command verification baseline.

  **Acceptance Criteria** (agent-executable only):
  - [ ] `grep -R "RepositoryInterface\|class .*Repository" app bootstrap config routes` returns no matches.
  - [ ] `php artisan route:list | grep -E "api/v1/(register|login|me|logout)"` returns all four routes.
  - [ ] `php artisan test` exits 0.
  - [ ] `php artisan migrate:fresh --force` exits 0.

  **QA Scenarios** (MANDATORY - task incomplete without these):
  ```text
  Scenario: Happy path final end-to-end gate
    Tool: Bash
    Steps: execute full gate script (config clear, migrate fresh, run tests, route check, repository anti-pattern check).
    Expected: all checks pass with exit code 0.
    Evidence: .sisyphus/evidence/task-10-final-gate.txt

  Scenario: Failure path scope violation
    Tool: Bash
    Steps: run anti-pattern scan for disallowed artifacts (`Repository`, `roles`, `password reset`).
    Expected: any match fails gate with explicit file path output.
    Evidence: .sisyphus/evidence/task-10-final-gate-error.txt
  ```

  **Commit**: YES | Message: `chore(qa): enforce final api scaffold compliance gate` | Files: `app/**`, `routes/**`, `tests/**`, `.github/workflows/ci.yml`

## Final Verification Wave (4 parallel agents, ALL must APPROVE)
- [x] F1. Plan Compliance Audit - oracle
- [x] F2. Code Quality Review - unspecified-high
- [x] F3. Real Manual QA - unspecified-high (+ playwright if UI)
- [x] F4. Scope Fidelity Check - deep

## Commit Strategy
- Commit after each logical milestone:
  - `chore(bootstrap): initialize laravel 11 api scaffold`
  - `feat(auth): add sanctum pat auth with service-layer flow`
  - `test(auth): add auth feature tests and ci workflow`
- Avoid squashing across milestones to preserve auditability.

## Success Criteria
- All acceptance criteria from Tasks 1-10 pass via agent-executed commands.
- No repository-pattern artifacts detected by static search.
- Auth behavior is deterministic for happy path and failure path scenarios.
- CI passes on default branch with no manual intervention.
