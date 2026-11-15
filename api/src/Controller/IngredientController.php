<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Repository\IngredientRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read/create only: shared names must not be renamed or deleted by API users. */
final class IngredientController extends AbstractController
{
    public function __construct(private IngredientRepository $ingredients, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/ingredients', name: 'api_ingredient_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $parameters = $request->query->all();
        $query = $parameters['q'] ?? '';
        $limit = $parameters['limit'] ?? '20';
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || !is_string($limit) || !preg_match('/^(?:[1-9][0-9]?|100)$/D', $limit)) {
            return $this->json(['error' => 'q must be a string and limit must be an integer from 1 to 100'], 400);
        }
        $query = Ingredient::normalizeName($query);
        if (mb_strlen($query) > 150 || preg_match('/[\p{Cc}\p{Cf}]/u', $query)) {
            return $this->json(['error' => 'q must not exceed 150 characters or contain control characters'], 400);
        }

        return $this->json($this->ingredients->search($query, (int) $limit));
    }

    #[Route('/api/ingredients', name: 'api_ingredient_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (JsonException) {
            return $this->json(['error' => 'Invalid JSON request body'], 400);
        }
        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            return $this->json(['error' => 'Ingredient name must be a string'], 400);
        }
        try {
            $ingredient = (new Ingredient())->setName($name);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 400);
        }
        $key = mb_strtolower($ingredient->getName(), 'UTF-8');
        if ($this->ingredients->findOneBy(['canonicalName' => $key]) !== null) {
            return $this->duplicate();
        }
        try {
            $this->entityManager->persist($ingredient);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Covers concurrent requests that both passed the friendly pre-check.
            return $this->duplicate();
        }

        return $this->json(['id' => $ingredient->getId(), 'name' => $ingredient->getName()], 201, [
            'Location' => $this->generateUrl('api_ingredient_show', ['id' => $ingredient->getId()]),
        ]);
    }

    #[Route('/api/ingredients/{id}', name: 'api_ingredient_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $ingredient = preg_match('/^[1-9][0-9]{0,9}$/D', $id) && (int) $id <= 2147483647
            ? $this->ingredients->find((int) $id) : null;
        if ($ingredient === null) {
            return $this->json(['error' => 'Ingredient not found'], 404);
        }

        return $this->json(['id' => $ingredient->getId(), 'name' => $ingredient->getName()]);
    }

    private function duplicate(): JsonResponse
    {
        return $this->json(['error' => 'Ingredient already exists'], 409);
    }
}
