# Culinary Compass — MVP Requirements

**Status:** Draft  
**Issue:** CC-004

## 1. Product Goal

Build a meal-planning platform that helps users manage recipes, plan weekly meals, track pantry ingredients and identify what groceries they need to buy.

## 2. MVP Features

- **Accounts:** Users can register, log in and log out. Each user's recipes, pantry and meal plans are private.
- **Recipes:** Users can create, view, update and delete recipes. Each recipe has a name, servings, ingredients with quantities and units, and cooking instructions.
- **Meal planning:** Users can assign recipes to dates and meal slots (breakfast, lunch and dinner), and update or remove planned meals.
- **Pantry:** Users can add, update and remove ingredients with quantities and measurement units.
- **Shopping list:** Users can generate a shopping list for a selected week. Required ingredient quantities are combined across planned recipes and reduced by matching pantry quantities.

## 3. Shopping List Rules

- Only ingredients with a remaining shortfall appear on the shopping list.
- Quantities are combined and subtracted only for the same ingredient and compatible measurement units.
- Automatic unit conversion is not required initially; incompatible units must not be silently subtracted.
- Generating a shopping list does not automatically reduce pantry stock.

## 4. Technical Requirements

The platform will use a Symfony REST API, PostgreSQL database and React/TypeScript web frontend.

The API must validate incoming data, enforce user ownership, return appropriate HTTP status codes and support automated testing of important business rules.

## 5. Out of Scope

AI recommendations, importing recipes from external websites, mobile applications, supermarket checkout integrations and advanced nutritional analysis are not part of the initial MVP.

## 6. Acceptance Scenarios

**Scenario A — Pantry shortage**

Given a planned recipe requires 500 g rice and the user's pantry contains 100 g rice, the shopping list should show 400 g rice.

**Scenario B — Multiple recipes**

Given two planned recipes require 500 g and 250 g of the same rice, and the pantry contains 100 g, the shopping list should show 650 g rice.

**Scenario C — Sufficient stock**

When the pantry contains enough of an ingredient for planned recipes, that ingredient should not appear on the shopping list.

**Scenario D — User isolation**

One authenticated user must not be able to retrieve or modify another user's private recipes, pantry or meal plans.

**Scenario E — Meal plan changes**

Removing a recipe from the meal planner must update the generated shopping list to reflect the remaining planned meals.

## 7. Development Approach

Development will proceed incrementally, starting with a Symfony API skeleton and foundational tests, followed by recipe management, meal planning, pantry management and shopping-list calculations.

The existing PHP prototype will be reviewed incrementally as relevant features are rebuilt.