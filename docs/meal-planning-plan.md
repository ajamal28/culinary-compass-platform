# Weekly meal planning proposal (CC-016 / #27)

Status: architecture proposal only; no meal-planning code or migration exists yet.
Implement after ingredient catalogue approval and merge, on a dedicated branch.
This proposal follows [MVP requirements](mvp-requirements.md), especially recipe
repetition, private ownership and shopping-list recalculation. The decisions
below must be settled before implementation.

## Proposed model

Use one `PlannedMeal` entity per scheduled occurrence. A separate weekly-plan
entity adds no required behavior: weeks can be selected by date range.

| Field | Doctrine/PostgreSQL representation | Rule |
| --- | --- | --- |
| id | generated integer primary key | identifies an occurrence, not a recipe |
| owner | required ManyToOne to User | assigned from JWT principal |
| recipe | required ManyToOne to Recipe | recipe must belong to owner |
| plannedDate | DATE / date_immutable | calendar date, without time or timezone |
| mealSlot | varchar(9), backed by a PHP enum | breakfast, lunch, dinner only |

Add an index on `(owner_id, planned_date)` for weekly queries. Keep the owner
column for direct scoped lookups and verify recipe ownership on every create or
recipe reassignment. Ownership cannot be changed through request fields.

Allow the same recipe in multiple occurrences, including the same week. Proposed
default: multiple entries may occupy the same date/slot, each with its own ID;
do not introduce a unique constraint per recipe/week or date/slot without a
product decision. Each occurrence contributes once to later ingredient totals.

Proposed deletion policy: restrict recipe deletion while it has planned meals,
returning a controlled `409` from DELETE recipe. Users can first remove or
reassign those occurrences. This preserves their plans, but changes an existing
API behavior and requires explicit acceptance before implementation. Add the
foreign key, conflict handling, documentation and tests together.

## Proposed API contract

All routes require JWT authentication. Responses expose only occurrence and
recipe summary fields, not owner details or complete Doctrine entities.

| Method | Route | Success |
| --- | --- | --- |
| POST | `/api/planned-meals` | `201`, occurrence and Location |
| GET | `/api/planned-meals?weekStart=2026-11-16` | `200`, ordered occurrence array |
| GET | `/api/planned-meals/{id}` | `200`, owned occurrence |
| PATCH | `/api/planned-meals/{id}` | `200`, updated occurrence |
| DELETE | `/api/planned-meals/{id}` | `204`, empty body |

Creation body:

```json
{"recipeId":42,"date":"2026-11-16","mealSlot":"dinner"}
```

Example representation:

```json
{"id":7,"recipeId":42,"recipeName":"Rice and beans","date":"2026-11-16","mealSlot":"dinner"}
```

Weekly retrieval requires a Monday `weekStart` in exact `YYYY-MM-DD` format.
Query `[weekStart, weekStart + 7 days)` and order by date, breakfast/lunch/dinner,
then occurrence ID. Return `[]` for an empty week. Join the recipe summary in the
same query to avoid one recipe lookup per occurrence. The first implementation
can return all owned occurrences in that bounded week; no unbounded all-time list.

PATCH accepts any subset of `recipeId`, `date` and `mealSlot`. Omitted fields stay
unchanged, explicit null is invalid, and `{}` is a no-op. Validate the complete
proposed update before mutating the managed entity. No per-occurrence quantity
or servings overrides are proposed for the MVP.

## Validation and security

- Validate types before assignment: recipeId is a positive PostgreSQL-range JSON
  integer; date and mealSlot are strings. Date must round-trip exactly through a
  strict calendar parser, rejecting overflow dates, times and trailing text.
- Validate Monday week boundaries, leap days, and the database-supported date
  range. Reject unknown meal slots, arrays, booleans and null with JSON `400`.
- Resolve recipes with both recipe ID and authenticated owner; return `404` for
  foreign or nonexistent recipe references without disclosing foreign names.
- Find occurrences by both ID and owner for read, PATCH and DELETE. Check that
  ownership before parsing mutation bodies. Ignore forged ownership fields.
- Return `401` for absent/invalid/expired JWT, `404` for missing/foreign records,
  and `409` for the proposed scheduled-recipe deletion conflict.
- Keep foreign keys as final integrity protection. Translate a concurrent
  recipe deletion into a controlled response; never expose SQL or constraints.
- Weekly reads and future shopping-list generation do not mutate pantry stock.
  Compute future requirements using current recipe ingredient assignments and
  all occurrence rows, preserving unit-compatibility rules. Do not cache totals
  in the planned-meal entity or snapshot ingredient data prematurely.

## Tests and implementation order

1. Settle multiple entries per date/slot, Monday week boundaries and recipe-delete
   conflict behavior. Document the agreed contract.
2. Add the entity/slot enum and reviewed migration. Verify forward and rollback
   on the intended local database and isolated test database separately.
3. Add owner-scoped create/detail/delete, followed by weekly queries and PATCH.
   Keep controller orchestration narrow and reuse validation where appropriate.
4. Add functional tests for successful scheduling, persisted date/slot/recipe,
   movement to another week/slot, reassignment, removal and empty weeks.
5. Test repeated recipes across days/weeks and multiple entries in one slot;
   every occurrence must remain separately addressable. Verify 200 g scheduled
   twice contributes 400 g before pantry subtraction in later calculation tests.
6. Test malformed JSON, invalid IDs/dates/slots/types, leap days, Sunday/Monday
   boundaries, invalid PATCH atomicity, JWT errors and forged owner fields.
7. Use two users to test list/detail/mutation isolation and attempts to schedule
   or reassign a foreign recipe. Test recipe-delete conflicts and unchanged
   database state after rejected operations.
8. Verify focused tests, full suite, mapping/schema, route table and relevant CI.
   Clean fixtures reliably and keep secrets and generated files out of commits.
9. Present changes and test evidence for push approval, then prepare a PR for
   owner review. Merge and deployment remain separate actions.
