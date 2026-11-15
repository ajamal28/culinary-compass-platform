<?php

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\User;
use App\Repository\RecipeRepository;
use App\Service\RecipeInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RecipeController extends AbstractController
{
    public function __construct(
        private RecipeRepository $recipes,
        private RecipeInput $input,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/recipes', name: 'api_recipe_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $recipe = (new Recipe())->setOwner($user);
        if ($error = $this->readInput($recipe, $request)) {
            return $error;
        }
        $this->entityManager->persist($recipe);
        $this->entityManager->flush();

        return $this->json($this->representation($recipe), Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('api_recipe_show', ['id' => $recipe->getId()]),
        ]);
    }

    #[Route('/api/recipes', name: 'api_recipe_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(array_map(
            $this->representation(...),
            $this->recipes->findBy(['owner' => $user], ['id' => 'ASC']),
        ));
    }

    #[Route('/api/recipes/{id}', name: 'api_recipe_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $recipe = $this->ownedRecipe($id);

        return $recipe === null ? $this->notFound() : $this->json($this->representation($recipe));
    }

    #[Route('/api/recipes/{id}', name: 'api_recipe_update', methods: ['PUT'])]
    #[Route('/api/recipes/{id}', name: 'api_recipe_patch', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $recipe = $this->ownedRecipe($id);
        if ($recipe === null) {
            return $this->notFound();
        }
        if ($error = $this->readInput($recipe, $request)) {
            return $error;
        }
        $this->entityManager->flush();

        return $this->json($this->representation($recipe));
    }

    #[Route('/api/recipes/{id}', name: 'api_recipe_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $recipe = $this->ownedRecipe($id);
        if ($recipe === null) {
            return $this->notFound();
        }
        $this->entityManager->remove($recipe);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function ownedRecipe(string $id): ?Recipe
    {
        $user = $this->getUser();
        if (!$user instanceof User || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 2147483647) {
            return null;
        }

        return $this->recipes->findOneBy(['id' => (int) $id, 'owner' => $user]);
    }

    private function readInput(Recipe $recipe, Request $request): ?JsonResponse
    {
        try {
            $data = $request->toArray();
            if ($request->isMethod('PATCH') && !str_starts_with(ltrim($request->getContent()), '{')) {
                throw new BadRequestHttpException('Recipe patch must be a JSON object');
            }
            $this->input->apply($recipe, $data, partial: $request->isMethod('PATCH'));
        } catch (JsonException) {
            return $this->json(['error' => 'Invalid JSON request body'], Response::HTTP_BAD_REQUEST);
        } catch (HttpExceptionInterface $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->getStatusCode());
        }

        return null;
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Recipe not found'], Response::HTTP_NOT_FOUND);
    }

    private function representation(Recipe $recipe): array
    {
        $ingredients = [];
        foreach ($recipe->getRecipeIngredients() as $link) {
            $ingredients[] = [
                'ingredientId' => $link->getIngredient()->getId(),
                'name' => $link->getIngredient()->getName(),
                'quantity' => $link->getQuantity(),
                'unit' => $link->getUnit(),
            ];
        }
        usort($ingredients, static fn (array $a, array $b): int => $a['ingredientId'] <=> $b['ingredientId']);

        return [
            'id' => $recipe->getId(),
            'name' => $recipe->getName(),
            'servings' => $recipe->getServings(),
            'instructions' => $recipe->getInstructions(),
            'ingredients' => $ingredients,
        ];
    }
}
