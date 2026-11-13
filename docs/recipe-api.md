# Recipe API (CC-009)

Implemented on `feature/recipe-api`; pending owner review and merge. All routes
require `Authorization: Bearer <JWT>` from `POST /api/login_check`.

| Method | Route | Success |
| --- | --- | --- |
| POST | `/api/recipes` | `201`, recipe JSON and `Location` header |
| GET | `/api/recipes` | `200`, JSON array of owned recipes ordered by ID; `[]` when empty |
| GET | `/api/recipes/{id}` | `200`, owned recipe JSON |
| PUT | `/api/recipes/{id}` | `200`, replacement recipe JSON |
| DELETE | `/api/recipes/{id}` | `204`, empty body |

## Create and replace

POST and PUT accept the same JSON body:

```json
{
  "name": "Rice and beans",
  "servings": 2,
  "instructions": "Cook together.",
  "ingredients": [
    {"ingredientId": 1, "quantity": "150.000", "unit": "g"},
    {"ingredientId": 2, "quantity": "20.5", "unit": "g"}
  ]
}
```

- `name`: string, trimmed, 1–150 characters.
- `servings`: JSON integer, 1–2147483647 (PostgreSQL integer range).
- `instructions`: string, trimmed, must not be blank.
- `ingredients`: optional list, defaults to `[]`. Each entry references an
  existing reusable Ingredient via a positive integer `ingredientId`.
- `quantity`: positive decimal **string**, maximum seven integer digits and
  three fractional digits (`0.001`–`9999999.999`). No signs, exponent notation,
  leading zeroes (except `0.x`), or whitespace. Responses use three decimal
  places. Numeric JSON values are rejected to avoid float rounding.
- `unit`: string, trimmed, 1–20 characters. Unit spelling and case are preserved;
  no unit conversion or compatibility inference happens here.
- Each ingredient ID may appear only once per recipe, even with different units.
- Quantities describe the whole recipe for its stated servings. Changing servings
  does not automatically scale quantities.
- PUT requires all three basic fields and replaces ingredient assignments;
  omitted `ingredients` or `[]` removes all links. Validation completes before
  mutation, so rejected requests leave existing recipes and links unchanged.
- Extra fields are ignored. `owner`, `owner_id`, and `userId` cannot set or change
  ownership; the JWT principal determines ownership.

Ingredient catalogue creation/discovery has no HTTP endpoint in this change.
Ingredient IDs in the example must already exist; no ingredient is created or
renamed by recipe writes. This API supports attaching, changing and removing
existing ingredient assignments; a client-facing catalogue remains follow-up work.

## Responses and errors

A recipe has only `id`, `name`, `servings`, `instructions`, and `ingredients`.
Each returned assignment has `ingredientId`, `name`, `quantity`, and `unit`;
assignments are ordered by ingredient ID. Passwords and owner details are omitted.

```json
{
  "id": 42,
  "name": "Rice and beans",
  "servings": 2,
  "instructions": "Cook together.",
  "ingredients": [
    {"ingredientId": 1, "name": "Rice", "quantity": "150.000", "unit": "g"},
    {"ingredientId": 2, "name": "Beans", "quantity": "20.500", "unit": "g"}
  ]
}
```

- `400`: malformed JSON, invalid types, missing required fields, invalid quantity,
  unit or ingredient reference. Body: `{"error":"<validation message>"}`.
- `401`: missing, invalid or expired JWT; existing security handlers return JSON
  with `code` and `message`.
- `404`: missing, invalid or foreign recipe ID, consistently returning
  `{"error":"Recipe not found"}`. Ownership is checked before parsing PUT input.
- `409`: duplicate ingredient assignments, returning
  `{"error":"An ingredient may appear only once per recipe"}`.

Deleting a recipe removes only that recipe and its ingredient links, preserving
shared Ingredient rows and other recipes. List and detail operations reveal only
the authenticated user's recipes. Meal planning, pantry subtraction and shopping
lists remain separate future work.

## Local verification and review

From `api/`, run `php bin/phpunit tests/Controller/RecipeControllerTest.php`,
then `php bin/phpunit`. Functional fixtures require the isolated
`culinary_compass_test` PostgreSQL database and configured local JWT test keys.
Do not commit keys or local environment overrides.

Follow the file-by-file review and approval gate in [AGENTS.md](../AGENTS.md).
These changes must be reviewed and approved before commit or push.
