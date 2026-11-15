# Ingredient catalogue API (CC-017)

Implemented on `feature/cc-017-ingredient-catalogue`; pending review and merge.
Every route requires a valid JWT from `POST /api/login_check`.

Ingredients form a shared catalogue. Any authenticated user can read and create
ingredient names; recipe ownership and quantities remain private. Users should
enter generic ingredient names, not personal notes. There are no ingredient
rename or delete endpoints: those changes could affect other users' recipes.

| Method | Route | Success |
| --- | --- | --- |
| GET | `/api/ingredients?q=rice&limit=20` | `200`, array of `{id, name}` |
| POST | `/api/ingredients` | `201`, `{id, name}` and `Location` |
| GET | `/api/ingredients/{id}` | `200`, `{id, name}` |

## Search

`q` is an optional UTF-8 string, maximum 150 characters after whitespace
normalization. Matching is a case-insensitive literal substring; `%`, `_`, and
`!` are ordinary characters, not SQL wildcards. Accents are significant. A
missing or blank query returns the first bounded page of the catalogue.

`limit` defaults to 20 and accepts a decimal integer string from 1 to 100.
Arrays, signs, fractions, leading zeros, and out-of-range values return `400`.
Results are ordered by normalized name in database collation order, then ID.
Search uses one bounded scalar query; it never loads recipe relationships or
performs a separate total-count query. Pagination beyond this first page is not
included in the MVP contract.

## Create

```json
{"name":"Basmati Rice"}
```

- `name` must be a string; JSON numbers, booleans, arrays and null are rejected.
- Unicode whitespace runs become one space, and surrounding whitespace is
  removed. The resulting display name must contain 1–150 characters.
- Remaining control and format characters, including NUL and zero-width space,
  are rejected. Display case is preserved.
- Uniqueness uses a separate internal key: normalized whitespace followed by
  UTF-8 lowercasing. `Basmati Rice`, `basmati rice`, and ` BASMATI  RICE ` refer
  to the same name. No accent folding or Unicode composition normalization is
  performed; semantic synonyms are not automatically combined.
- A unique database index protects concurrent creation. An existing name,
  including a concurrent insert after the initial pre-check, returns `409`.
- Extra fields are ignored. Ownership fields do not make catalogue entries
  private. Neither canonical keys nor user details appear in responses.

The response is `{"id":123,"name":"Basmati Rice"}` with
`Location: /api/ingredients/123`. This ID can immediately be used as
`ingredientId` in an owned recipe. Multiple users may reuse the same ingredient
with different recipe quantities and units. Deleting a recipe preserves it.

## Errors

- `400`: invalid query parameters, malformed JSON, missing/invalid name, or
  invalid name length/characters. Body: `{"error":"<validation message>"}`.
- `401`: missing, invalid or expired JWT, using existing authentication handlers.
- `404`: missing/invalid ingredient ID. Body: `{"error":"Ingredient not found"}`.
- `409`: duplicate name. Body: `{"error":"Ingredient already exists"}`.
- `405`: PUT, PATCH or DELETE on an ingredient; these mutation routes do not exist.

## Migration and verification

`Version20261115150000` adds an internal `canonical_name` column, backfills it
without changing IDs or display names, and adds the unique index. Its wider
450-character limit accommodates Unicode lowercase expansion from a 150-character
display name. The migration stops before schema changes if existing names are
invalid or normalize to duplicate keys; resolve conflicting IDs manually without
breaking existing recipe references. It never silently merges or deletes data.
Rollback removes the index and internal column while preserving ingredients.

Confirm the target database before migrations. Apply the migration separately to
the intended local database and `culinary_compass_test`. From `api/`:

```powershell
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/phpunit tests/Controller/IngredientControllerTest.php tests/Migrations/IngredientCatalogueMigrationTest.php
php bin/phpunit
```

Migration tests temporarily exercise rollback/backfill inside PostgreSQL
transactions, assert the isolated test database name and restore schema/data in
teardown. API tests cover JWT errors, duplicates, literal search, validation,
cross-user catalogue reuse and private recipe isolation.
