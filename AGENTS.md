# AGENTS.md — Culinary Compass

Repository-wide guidance for AI coding agents and contributors. These instructions apply from the repository root. Read the current code before making assumptions; a plan in this file does not mean a feature has been implemented.

## 1. Purpose and sources of truth

Culinary Compass is an **API-first meal-planning platform**. Its MVP lets people manage private recipes, schedule meals, track pantry stock, and generate shopping lists from planned meals minus compatible pantry quantities. The backend should be reusable by a web client and, later, mobile clients.

Use these sources in order:

1. `docs/mvp-requirements.md` — product scope, business rules and acceptance scenarios; the primary product specification.
2. Current source code, migrations and tests — the authority for what is actually implemented.
3. `README.md`, `docs/README.md` and `SECURITY.md` — repository context and security policy.
4. The current GitHub issue, pull request and user instructions — scope of the task at hand.

If documentation and code disagree, explain the discrepancy; do not silently rewrite the specification or assume a planned feature exists.

## 2. Confirmed stack and environment

- **Backend:** PHP 8.4 for local development and CI, Symfony 7.4, Doctrine ORM and migrations.
- **Database:** PostgreSQL (version 18 in the existing local/CI setup).
- **Authentication:** Symfony Security with LexikJWTAuthenticationBundle 3.2 and RSA signing keys.
- **Tests:** PHPUnit functional and unit tests; GitHub Actions runs PHPUnit against a temporary PostgreSQL service.
- **Planned frontend:** React + TypeScript; not yet present in the committed repository.
- **Future only:** React Native, AI recommendations and additional infrastructure; do not implement them as part of the MVP without explicit scope changes.
- **Local setup:** Windows PowerShell and local PostgreSQL. Do not assume Docker, WSL2 or a Linux VPS is available. Remote staging/VPS deployment is deferred.

Follow the versions actually pinned in `api/composer.json` and `api/composer.lock` instead of guessing or upgrading dependencies as a side effect.

## 3. Repository layout

```text
culinary-compass-platform/
├── AGENTS.md                       # These repository instructions
├── README.md                       # Product overview and roadmap
├── SECURITY.md                     # Security reporting policy
├── docs/
│   ├── README.md                   # Engineering documentation index
│   └── mvp-requirements.md          # MVP specification and acceptance cases
├── .github/
│   └── workflows/
│       └── phpunit.yml             # PHP + PostgreSQL + JWT test CI
└── api/                             # Symfony application; PHP commands run here
    ├── bin/                         # Symfony console and PHPUnit entry points
    ├── config/
    │   ├── packages/               # Doctrine, security, JWT and framework config
    │   ├── routes/                 # API login and framework routes
    │   └── jwt/                    # Local RSA keys (*.pem must remain untracked)
    ├── migrations/                 # Versioned Doctrine migrations
    ├── public/                     # HTTP entry point
    ├── src/
    │   ├── Controller/             # HTTP API endpoints
    │   ├── Entity/                 # Doctrine domain entities
    │   └── Repository/             # Database queries
    ├── tests/
    │   ├── Controller/             # HTTP functional tests
    │   └── Entity/                 # Domain/unit tests
    ├── composer.json
    ├── composer.lock
    └── phpunit.dist.xml
```

`web/` is a **planned** frontend directory, not an existing application. Do not invent deployed services or create a frontend scaffold unless requested. `api/config/reference.php` is generated/reference configuration; avoid unrelated edits.

## 4. Current implementation and next work

Implemented in the mainline backend: health check, user registration, JWT login, protected `/api/me`, `User`, `Recipe`, `Ingredient`, `RecipeIngredient` entities, migrations and authentication tests.

The authenticated `POST /api/recipes` creation endpoint is under development on `feature/recipe-api` (draft PR #20 at the time these instructions were written). Check the live branch/PR before treating it as merged. Recipe listing, detail, update, delete, meal scheduling, pantry and shopping-list generation are not yet complete.

Prefer to finish **recipe CRUD and ownership tests** before moving to meal planning, pantry and shopping-list calculations. Keep ingredients/quantities as an explicit follow-up to basic recipe creation unless the issue requires them.

## 5. Domain model and business invariants

- `User` owns private recipes, and later private pantry and meal-plan records. Never take an `owner_id` or user ID from untrusted JSON when determining ownership.
- `Recipe` has `name` (max 150 characters), positive integer `servings`, cooking `instructions`, and a required `owner` foreign key.
- `Ingredient` represents a reusable ingredient, independent of a recipe.
- `RecipeIngredient` links recipe and ingredient and stores positive decimal `quantity` (`NUMERIC(10,3)` represented as a string by Doctrine) and `unit` (max 20 characters). `(recipe_id, ingredient_id)` is unique.
- Deleting a recipe may remove its ingredient links, **not** globally shared `Ingredient` records.
- A recipe may be scheduled multiple times in the same week or across weeks. Each scheduled occurrence contributes separately to shopping-list totals.

MVP shopping-list rules from `docs/mvp-requirements.md`:

- Sum requirements across **all occurrences** of scheduled recipes in the selected week.
- Subtract matching pantry stock **only when ingredient identity and units are compatible**. Do not silently convert or subtract incompatible units.
- Show **only positive shortfalls**; sufficient pantry stock produces no list item.
- Generating a shopping list must **not mutate pantry quantities**.
- Changes to scheduled meals must be reflected in the next generated shopping list.

Preserve these regression examples: 500 g rice required minus 100 g pantry = **400 g**; 500 g + 250 g minus 100 g = **650 g**; 200 g scheduled twice = **400 g before pantry subtraction**.

## 6. API conventions

- Use Symfony attribute routes for application controllers and the existing JSON login route configuration.
- Existing public routes include `POST /api/register`, `POST /api/login_check` and `GET /api/health`. Other `/api` routes are protected by JWT as configured in `api/config/packages/security.yaml`.
- Use `$request->toArray()` for JSON request bodies where appropriate. Catch `Symfony\Component\HttpFoundation\Exception\JsonException` and return a controlled `400` for malformed JSON.
- Validate **types before trimming, comparing or assigning**. Reject blank names/instructions, noninteger or nonpositive servings, and names exceeding database limits. Validate identifiers, amounts and units before persistence.
- Return consistent JSON with meaningful HTTP codes: `200` for reads, `201` for creation, `204` for successful no-content deletion, `400` for invalid input, `401` for missing/invalid authentication, `403` or consistently chosen `404` for forbidden resources, and `409` for conflicts.
- Assign new records to `$this->getUser()` after confirming the authenticated principal is an `App\Entity\User`. Do **not** trust `owner`, `userId`, or `owner_id` supplied in request bodies.
- For every record read/update/delete, constrain lookup by the authenticated owner (or enforce a tested voter/policy). Never retrieve a foreign user's private record merely because the client knows its ID.
- Avoid directly serializing entire Doctrine entities or exposing password hashes, JWT secrets or unnecessary internal fields.
- Prefer clear, narrow controller actions; move reusable business logic (especially shopping-list calculations) to dedicated services when it grows beyond orchestration.

## 7. Security requirements

- Preserve password hashing through Symfony's password hasher; never store or log plaintext passwords.
- RSA JWT **private keys**, passphrases and credentials must never be committed, printed or included in example responses. Keep `api/config/jwt/*.pem`, `.env.local` and `.env.*.local` untracked. Inspect staged diffs before commits; `api/.env` is tracked and must contain only safe defaults/placeholders.
- Treat JWTs as bearer credentials. Require HTTPS in any public deployment; do not place secrets in URLs or logs. Signed JWT payloads are readable, not encrypted.
- The JWT API is stateless: do not claim logout revokes issued tokens unless an explicit revocation or refresh-token mechanism has been implemented.
- Prevent insecure direct object reference (IDOR) failures across recipes, pantry, meal plans and shopping lists. Include cross-user tests for reads and mutations.
- Never connect tests to production or personal development data for destructive setup/cleanup. Do not provision paid infrastructure or deploy publicly without explicit authorization.
- Read `SECURITY.md` before changing vulnerability-reporting or disclosure behaviour.

## 8. Local commands and testing

Run PHP/Composer commands from `api/` (not the repository root):

```powershell
cd api
composer install
php bin/console doctrine:schema:validate
php bin/console debug:router
php bin/phpunit
php bin/phpunit tests/Controller/MeControllerTest.php
php -S 127.0.0.1:8000 -t public public/index.php
```

For schema changes, generate/review a Doctrine migration, apply it first to the intended local database and separately to the test database, and keep migration rollback order sane. Check the active environment/database **before** migration or cleanup commands.

Tests normally use `APP_ENV=test` and the separate `culinary_compass_test` PostgreSQL database. Doctrine's test configuration adds an `_test` suffix to the base database name; verify this rather than guessing. Local test overrides belong in ignored `api/.env.test.local`.

GitHub Actions (`.github/workflows/phpunit.yml`) starts PostgreSQL, generates **temporary** RSA JWT test keys, applies migrations and runs the suite. Never commit signing keys just to make CI pass. Windows may need `OPENSSL_CONF` pointed at PHP's installed `extras/ssl/openssl.cnf` when generating local keys.

For each endpoint or business rule, add tests for:

- Success and its database effects.
- Validation failures and appropriate HTTP/JSON errors.
- Missing, invalid and (where relevant) expired credentials.
- User ownership, including attempted cross-user access.
- Duplicate/conflicting input and edge cases.

Use isolated test data; clean it in `finally` or via reliable transaction/database-reset fixtures. Do not assume randomly generated emails alone provide cleanup.

## 9. How agents should work in this repository

1. Inspect the current branch, `git status`, the relevant spec sections, existing entities/controllers and tests **before editing**. Preserve unrelated changes.
2. State a small plan and explain the architectural/security reason for changes. The developer is learning and prefers **incremental, teaching-first guidance** over a large unexplained implementation.
3. Make focused, minimal changes that follow Symfony/Doctrine conventions; avoid premature abstractions or speculative features.
4. Add or update tests for every behaviour change. Run targeted tests, then the whole suite where the environment permits. Report honestly which tests were run and which could not be run.
5. Review `git diff` and ensure no secrets, accidental generated files or unrelated dependency upgrades are included.
6. Use concise Conventional Commit-style messages (`feat(recipe): ...`, `test(recipe): ...`, `fix(auth): ...`). Work on feature branches; do not merge or deploy without explicit instruction.
7. Update relevant documentation when an API contract, setup procedure or MVP assumption actually changes. Keep planned vs implemented functionality clearly distinguished.

## 10. Definition of done

### Agent review and approval workflow (CC-011)

This workflow also applies to changes to this file. The owner retains final control of agent-authored changes.

1. Read the issue, specification and current code; inspect the branch, working tree and staged diff. State a short implementation plan before editing.
2. Work in small file-level increments. After each modified file, present what changed, why, security or API impact, and a readable diff for owner review. Continue authorized implementation and testing while review is pending.
3. Run focused tests followed by relevant complete suites. Report passing and failing checks and checks not run, with reasons. Preserve unrelated changes and keep secrets, JWT keys, local environment files and generated files out of staging.
4. **STOP at the review gate:** do not commit, push, open or merge a PR, or deploy agent-authored changes until the owner explicitly approves the reviewed changes. Commit, push, opening a PR, merge and deployment are separate actions; approval for one does not authorize the others. If files change after approval, present the new diff for approval again.
5. At the gate, use the handoff template below. After approval, prepare the PR-ready summary, proposed Conventional Commit message and test evidence for the approved action. Never claim an issue is complete while required checks or approvals remain outstanding.

#### Review handoff template

- Issues and branch:
- Changed files (excluding preserved pre-existing changes):
- For each file: what changed / why / security or API impact:
- Meaningful diff: attach the readable diff or provide `git diff -- <paths>` (use `git diff --no-index -- /dev/null <path>` for an untracked file on systems that support it):
- Tests: command, result, and checks not run with reasons:
- Risks or limitations:
- Approval status: pending, or exact actions approved by the owner:

#### Small example: recipe validation fix

Read the recipe issue and tests on a feature branch, explain the validation change, edit the controller and present its diff. Add the regression test and present that file's diff. Run the focused test and full suite, then provide the handoff above with approval status **pending**. Stop without staging, committing, pushing or opening a PR. Once the owner approves a specific action, perform only that action on the reviewed files. Existing ownership, authentication and MVP scope requirements still apply.

A feature is ready for review when it satisfies the relevant acceptance criteria in `docs/mvp-requirements.md`, preserves user isolation, handles malformed inputs, has clear API responses, includes passing local/CI tests as applicable, and has no credentials or generated secrets in the diff. Do not mark a feature complete solely because a manual happy-path request succeeded.
