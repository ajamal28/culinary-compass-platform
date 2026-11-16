# Culinary Compass

**An API-first meal-planning application, currently under development.**

I'm building Culinary Compass as a personal software engineering project to make it easier to organise recipes, plan meals for the week and work out what to buy based on what's already in the pantry.

The current repository contains a working **Symfony REST API** for account registration, JWT login and private recipe management. The weekly planner, pantry, shopping-list calculations and React frontend are still to come. This is an evolving portfolio project, **not a finished or publicly deployed product**.

## Current progress

| Area | Status | Details |
| --- | --- | --- |
| Symfony API and PostgreSQL | Implemented | Symfony 7.4, Doctrine ORM and versioned migrations |
| Accounts and authentication | Implemented | Registration, login with JWT and protected user profile |
| Private recipe management | Implemented | Create, list, view, replace and delete recipes; ownership enforced |
| Recipe ingredients | Implemented | Existing ingredients can be assigned quantities and units to recipes |
| Partial recipe updates | In review | `PATCH /api/recipes/{id}` in [PR #31](https://github.com/ajamal28/culinary-compass-platform/pull/31) |
| Ingredient catalogue | In review | Search and creation endpoints in [PR #32](https://github.com/ajamal28/culinary-compass-platform/pull/32) |
| Weekly meal planner | Design proposed | [PR #33](https://github.com/ajamal28/culinary-compass-platform/pull/33) is an architecture proposal, not an implementation |
| Pantry and shopping lists | Planned | Quantities, unit compatibility and shopping-list shortfall calculations |
| React / TypeScript web interface | Planned | No frontend application is present yet |

The statuses above describe the **`main` branch**. Open pull requests are not listed as completed features.

## Technology and structure

- **Backend:** PHP 8.4 (development and CI), Symfony 7.4, Doctrine ORM and migrations.
- **Database:** PostgreSQL 18 in the documented local and CI environment.
- **Authentication:** Symfony Security and LexikJWTAuthenticationBundle with RSA-signed JWTs.
- **Testing:** PHPUnit functional and entity tests, with PostgreSQL-backed CI in GitHub Actions.
- **Development tooling:** Composer, Git and GitHub pull requests/issues.
- **Planned web client:** React and TypeScript (not yet implemented).

```text
api/                       Symfony application
  src/Controller/          JSON API endpoints
  src/Service/             Recipe input validation and assignment logic
  src/Entity/              User, Recipe, Ingredient, RecipeIngredient
  src/Repository/          Doctrine database access
  migrations/              Versioned schema changes
  tests/                   Functional and entity tests
docs/                      MVP requirements and API documentation
.github/workflows/         Automated PHPUnit checks
AGENTS.md                  Engineering and AI-assisted review guidelines
```

## Available API routes on `main`

| Method | Endpoint | Access | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/health` | Public | Health check |
| `POST` | `/api/register` | Public | Register an account |
| `POST` | `/api/login_check` | Public | Obtain a signed JWT |
| `GET` | `/api/me` | JWT | Retrieve the authenticated user |
| `POST` | `/api/recipes` | JWT | Create a recipe |
| `GET` | `/api/recipes` | JWT | List the user's recipes |
| `GET` | `/api/recipes/{id}` | JWT | Retrieve an owned recipe |
| `PUT` | `/api/recipes/{id}` | JWT | Replace an owned recipe's writable fields |
| `DELETE` | `/api/recipes/{id}` | JWT | Delete an owned recipe |

Recipe writes accept `name`, `servings`, `instructions` and optional ingredient assignments (`ingredientId`, decimal-string `quantity`, `unit`). The referenced ingredients must already exist; ingredient creation and search are still pending in PR #32. `PUT` is a full replacement, not a partial update. See the [Recipe API contract](docs/recipe-api.md).

## Run locally (Windows or Linux)

The project uses local PHP and PostgreSQL; Docker and WSL are **not required**. These are development instructions, not production deployment instructions. They have not been independently reproduced as part of this documentation review.

### Prerequisites

- Git, PHP 8.4 and Composer 2.
- PostgreSQL (version 18 in the documented environment).
- PHP extensions needed by the application and CI, including `mbstring`, `pdo_pgsql`, `pgsql`, `openssl` and `sodium`.
- OpenSSL support for generating local JWT signing keys.

### Option A: Windows (PowerShell)

```powershell
git clone https://github.com/ajamal28/culinary-compass-platform.git
cd culinary-compass-platform\api
Copy-Item .env.example .env.local
composer install
```

### Option B: Linux (Ubuntu / Debian, Bash)

Install PHP 8.4, Composer, PostgreSQL and the required PHP extensions. Package availability varies by Linux release: **use a trusted PHP 8.4 package source supported by your distribution** if the default repositories provide an older version. For distributions using `apt`, the package names may look like:

```bash
sudo apt update
sudo apt install -y git curl unzip openssl composer postgresql postgresql-client \
  php8.4-cli php8.4-mbstring php8.4-pgsql php8.4-xml \
  php8.4-curl php8.4-intl php8.4-zip
php -v
php -m  # Check that pdo_pgsql, pgsql, mbstring, openssl and sodium are available
```

Start PostgreSQL and, if you do not already have a suitable local database role, create one with permission to create the development and test databases. `--pwprompt` asks for the password interactively rather than including it in the command or shell history.

```bash
sudo systemctl start postgresql
sudo -u postgres createuser --pwprompt --createdb culinarycompass
```

Clone the project and install PHP dependencies:

```bash
git clone https://github.com/ajamal28/culinary-compass-platform.git
cd culinary-compass-platform/api
cp .env.example .env.local
composer install
```

The remainder of the setup commands below work in both PowerShell and Bash. On Linux, ensure `php` runs PHP 8.4 and the PostgreSQL service is running. `systemctl` is shown for distributions using systemd; service commands can differ on other distributions.

### Configure the environment and database

Edit **`api/.env.local`** with your own *local* values. For example:

```dotenv
APP_ENV=dev
APP_SECRET=REPLACE_WITH_A_RANDOM_LOCAL_VALUE
DATABASE_URL="postgresql://YOUR_USER:YOUR_PASSWORD@127.0.0.1:5432/culinary_compass?serverVersion=18&charset=utf8"
JWT_PASSPHRASE=REPLACE_WITH_A_PRIVATE_LOCAL_PASSPHRASE
```

Set the connection URL to your own PostgreSQL role, password and database. For the Linux example above, the username would be `culinarycompass`; URL-encode special characters in a database password. The role needs permission to create databases for the commands below, or you can create the databases separately as a PostgreSQL administrator. Never commit real credentials or private keys. Generate the local JWT key pair from `api/` after setting your passphrase:

```powershell
php bin/console lexik:jwt:generate-keypair
```

On Windows, local OpenSSL configuration may need to be set up separately. On Linux, verify that the OpenSSL and sodium PHP extensions are available. Signing keys in `api/config/jwt/` are gitignored.

Check your database connection details **before** running migrations. Then initialise the development database:

```powershell
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
```

Start the local API from `api/`:

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

The public health endpoint is then available at `http://127.0.0.1:8000/api/health`.

### Run automated tests (Windows or Linux)

Tests require a **separate PostgreSQL test database** and local JWT test keys. The project configuration derives `culinary_compass_test` from a base database named `culinary_compass`; verify the effective database name before running test migrations.

```powershell
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```

GitHub Actions also runs the PHPUnit suite using an isolated PostgreSQL service and temporary JWT keys. See [.github/workflows/phpunit.yml](.github/workflows/phpunit.yml).

## Engineering decisions I'm exploring

- **API first:** Keep the core logic in Symfony so the planned web and mobile clients can share it.
- **Private data by default:** Recipe reads and mutations are scoped to the authenticated owner, with cross-user access covered by tests.
- **Explicit API responses:** Controllers return selected fields rather than serialising complete Doctrine entities.
- **Reusable ingredients:** Recipe-to-ingredient relationships store quantities and units separately from shared ingredient identities.
- **Decimal accuracy:** Ingredient quantities are stored as decimal strings rather than relying on JSON floating-point values.
- **Testable changes:** PHPUnit exercises validation, authentication, ownership and persistence; GitHub Actions runs tests on pull requests.
- **Reviewed AI assistance:** Coding agents follow [AGENTS.md](AGENTS.md) and changes are subject to explicit owner review before Git operations.

There is **no automatic unit conversion** in the current recipe API. Pantry subtraction and shopping-list calculations are future functionality.

## Roadmap

1. Review and merge the recipe PATCH and ingredient catalogue work ([PR #31](https://github.com/ajamal28/culinary-compass-platform/pull/31), [PR #32](https://github.com/ajamal28/culinary-compass-platform/pull/32)).
2. Finalise the weekly meal-planning design and implement owner-scoped scheduling ([CC-016](https://github.com/ajamal28/culinary-compass-platform/issues/27)).
3. Build pantry stock management.
4. Calculate shopping lists from scheduled meals minus compatible pantry quantities, including repeated recipes.
5. Develop the React / TypeScript interface and end-to-end tests.
6. Revisit AI features, recipe imports and mobile clients **after** the core MVP.

## Documentation and security

- [MVP requirements](docs/mvp-requirements.md)
- [Recipe API contract](docs/recipe-api.md)
- [Engineering documentation](docs/README.md)
- [Agent working agreement](AGENTS.md)
- [Security policy](SECURITY.md)

This is an independent learning and portfolio project. with current repository contents and a copy of file Source in GitHub mind.
