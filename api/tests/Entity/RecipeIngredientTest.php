<?php
namespace App\Tests\Entity;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;

final class RecipeIngredientTest extends \PHPUnit\Framework\TestCase
{
    public function testAddingIngredientToRecipe(): void
    {
        # Create a recipe
        $recipe = new Recipe();
        $recipe->setName("Chicken and Rice");
        $recipe->setServings(2);
        $recipe->setInstructions("Cook the chicken and rice.");

        #Create the ingredients (rice and chicken)
        $rice = new Ingredient();
        $rice->setName("Rice");
        $chicken = new Ingredient();
        $chicken->setName('Chicken');

        #Create a recipe ingredient and add it to the recipe with the ingredient and quantity
        $recipeIngredient = new RecipeIngredient();
        $recipeIngredient->setQuantity("150.000");
        $recipeIngredient->setUnit("g");
        $recipeIngredient->setIngredient($rice);
        $recipe->addRecipeIngredient($recipeIngredient);

        $recipeIngredient2 = new RecipeIngredient();
        $recipeIngredient2->setQuantity("200.000");
        $recipeIngredient2->setUnit("g");
        $recipeIngredient2->setIngredient($chicken);
        $recipe->addRecipeIngredient($recipeIngredient2);

        # Assert that the recipe has 2 ingredients and that the ingredients and quantities are correctly associated with the recipe and the recipe ingredients
        self::assertCount(2, $recipe->getRecipeIngredients());
        self::assertSame($rice, $recipeIngredient->getIngredient());
        self::assertSame($chicken, $recipeIngredient2->getIngredient());
        self::assertSame($recipe, $recipeIngredient->getRecipe());
        self::assertSame($recipe, $recipeIngredient2->getRecipe());
        self::assertSame("150.000", $recipeIngredient->getQuantity());
        self::assertSame("200.000", $recipeIngredient2->getQuantity());

    }

    public function testIngredientCanBeUsedInMultipleRecipes(): void
    {
        #Create rice ingredient
        $rice = new Ingredient();
        $rice->setName("Rice");

        #Create two recipes
        $recipeA = new Recipe();
        $recipeA->setName("Chicken and Rice");
        $recipeA->setServings(2);
        $recipeA->setInstructions("Cook the chicken and rice.");

        $recipeB = new Recipe();
        $recipeB->setName("Vegetable Curry with Rice");
        $recipeB->setServings(4);
        $recipeB->setInstructions("Cook the vegetables and serve with rice.");

        #Create recipe ingredients for both recipes using the same rice ingredient
        $recipeIngredientA = new RecipeIngredient();
        $recipeIngredientA->setQuantity("150.000");
        $recipeIngredientA->setUnit("g");
        $recipeIngredientA->setIngredient($rice);

        $recipeIngredientB = new RecipeIngredient();
        $recipeIngredientB->setQuantity("250.000");
        $recipeIngredientB->setUnit("g");
        $recipeIngredientB->setIngredient($rice);

        # Add the recipe ingredients to their respective recipes
        $recipeA->addRecipeIngredient($recipeIngredientA);
        $recipeB->addRecipeIngredient($recipeIngredientB);


        self::assertSame($rice, $recipeIngredientA->getIngredient());
        self::assertSame($rice, $recipeIngredientB->getIngredient());
        self::assertSame($recipeA, $recipeIngredientA->getRecipe());
        self::assertSame($recipeB, $recipeIngredientB->getRecipe());
        self::assertCount(1, $recipeA->getRecipeIngredients());
        self::assertCount(1, $recipeB->getRecipeIngredients());

        self::assertSame("150.000", $recipeIngredientA->getQuantity());
        self::assertSame("250.000", $recipeIngredientB->getQuantity());

    }

    public function testRecipeCannotHaveZeroServings(): void
    {
        $recipe = new Recipe();

        $this->expectException(\InvalidArgumentException::class);
        $recipe->setServings(0);
    }

    public function testRecipeIngredientCannotHaveNegativeQuantity(): void
    {
        $recipeIngredient = new RecipeIngredient();

        $this->expectException(\InvalidArgumentException::class);
        $recipeIngredient->setQuantity("-50");
    }
}
