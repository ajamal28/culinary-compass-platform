<?php

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecipeController extends AbstractController
{
    #[Route(path: '/api/recipes', name: 'api_recipe_create',methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): JsonResponse {
        // 1. Get the authenticated user
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                ['error' => 'Unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // 2. Decode the JSON request body
        try {
            $data = $request->toArray();
        } catch (JsonException $e) {
            return $this->json(
                ['error' => 'Invalid JSON request body'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 3. Extract the fields
        $name = $data['name'] ?? null;
        $servings = $data['servings'] ?? null;
        $instructions = $data['instructions'] ?? null;

        // 4. Validate the recipe name
        if (!is_string($name) || trim($name) === '') {
            return $this->json(
                ['error' => 'Recipe name is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $name = trim($name);

        if (mb_strlen($name) > 150) {
            return $this->json(
                ['error' => 'Recipe name cannot exceed 150 characters'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 5. Validate servings
        if (!is_int($servings) || $servings <= 0) {
            return $this->json(
                ['error' => 'Servings must be a positive integer'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 6. Validate instructions
        if (!is_string($instructions) || trim($instructions) === '') {
            return $this->json(
                ['error' => 'Recipe instructions are required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $instructions = trim($instructions);

        // 7. Create the Recipe entity
        $recipe = new Recipe();

        $recipe->setName($name);
        $recipe->setServings($servings);
        $recipe->setInstructions($instructions);

        // The owner must be the authenticated user.
        $recipe->setOwner($user);

        // 8. Save to PostgreSQL
        $entityManager->persist($recipe);
        $entityManager->flush();

        // 9. Return the created recipe
        return $this->json(
            [
                'id' => $recipe->getId(),
                'name' => $recipe->getName(),
                'servings' => $recipe->getServings(),
                'instructions' => $recipe->getInstructions(),
            ],
            Response::HTTP_CREATED
        );
    }
}
