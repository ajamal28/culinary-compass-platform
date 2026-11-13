<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Repository\IngredientRepository;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Validates the entire replacement before changing a managed recipe. */
final class RecipeInput
{
    public function __construct(private IngredientRepository $ingredients)
    {
    }

    public function apply(Recipe $recipe, array $data): void
    {
        $name = $data['name'] ?? null;
        $servings = $data['servings'] ?? null;
        $instructions = $data['instructions'] ?? null;

        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 150) {
            throw new BadRequestHttpException('Recipe name must contain 1 to 150 characters');
        }
        if (!is_int($servings) || $servings < 1 || $servings > 2147483647) {
            throw new BadRequestHttpException('Servings must be a positive integer no greater than 2147483647');
        }
        if (!is_string($instructions) || trim($instructions) === '') {
            throw new BadRequestHttpException('Recipe instructions are required');
        }

        $items = $data['ingredients'] ?? [];
        if (array_key_exists('ingredients', $data) && (!is_array($items) || $data['ingredients'] === null || !array_is_list($items))) {
            throw new BadRequestHttpException('Ingredients must be a list');
        }

        $assignments = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new BadRequestHttpException('Each ingredient must be an object');
            }
            $id = $item['ingredientId'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $unit = $item['unit'] ?? null;
            if (!is_int($id) || $id < 1 || $id > 2147483647) {
                throw new BadRequestHttpException('Ingredient ID must be a positive integer');
            }
            if (isset($assignments[$id])) {
                throw new ConflictHttpException('An ingredient may appear only once per recipe');
            }
            // NUMERIC(10,3): seven integer digits and at most three decimal places.
            if (!is_string($quantity) || !preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,3})?$/D', $quantity)
                || !preg_match('/[1-9]/', $quantity)) {
                throw new BadRequestHttpException('Quantity must be a positive decimal string with at most 7 integer and 3 fractional digits');
            }
            if (!is_string($unit) || trim($unit) === '' || mb_strlen(trim($unit)) > 20) {
                throw new BadRequestHttpException('Unit must contain 1 to 20 characters');
            }
            $ingredient = $this->ingredients->find($id);
            if (!$ingredient instanceof Ingredient) {
                throw new BadRequestHttpException('Ingredient does not exist');
            }
            $parts = explode('.', $quantity);
            $assignments[$id] = [$ingredient, $parts[0].'.'.str_pad($parts[1] ?? '', 3, '0'), trim($unit)];
        }

        $recipe->setName(trim($name))->setServings($servings)->setInstructions(trim($instructions));
        // Reuse retained links: deleting and reinserting the same pair in one flush
        // would collide with the existing unique constraint before orphan deletion.
        foreach ($recipe->getRecipeIngredients()->toArray() as $link) {
            $id = $link->getIngredient()->getId();
            if (!isset($assignments[$id])) {
                $recipe->removeRecipeIngredient($link);
                continue;
            }
            [, $quantity, $unit] = $assignments[$id];
            $link->setQuantity($quantity)->setUnit($unit);
            unset($assignments[$id]);
        }
        foreach ($assignments as [$ingredient, $quantity, $unit]) {
            $link = (new RecipeIngredient())->setIngredient($ingredient)->setQuantity($quantity)->setUnit($unit);
            $recipe->addRecipeIngredient($link);
        }
    }
}
