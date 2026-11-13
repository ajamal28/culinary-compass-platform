<?php

namespace App\Tests\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecipeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $userIds = [];
    private array $ingredientIds = [];
    private array $tokens = [];
    private string $expiredToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Fail before creating or cleaning fixtures on any other database.
        self::assertSame('culinary_compass_test', $em->getConnection()->fetchOne('SELECT current_database()'));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        foreach (['owner', 'other'] as $label) {
            $user = (new User())->setEmail('recipe-'.$label.'-'.$suffix.'@example.com');
            $user->setPassword($hasher->hashPassword($user, 'RecipeTestPassword123!'));
            $em->persist($user);
            $em->flush();
            $this->userIds[] = $user->getId();
            $this->tokens[] = $jwt->create($user);
            if ($label === 'owner') {
                $this->expiredToken = $jwt->createFromPayload($user, ['iat' => time() - 7200, 'exp' => time() - 3600]);
            }
        }
        foreach (['Rice', 'Beans', 'Salt'] as $name) {
            $ingredient = (new Ingredient())->setName($name.' '.$suffix);
            $em->persist($ingredient);
            $em->flush();
            $this->ingredientIds[] = $ingredient->getId();
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== [] || $this->ingredientIds !== []) {
                $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
                self::assertSame('culinary_compass_test', $connection->fetchOne('SELECT current_database()'));
                $connection->transactional(function ($db): void {
                    foreach ($this->userIds as $id) {
                        $db->executeStatement('DELETE FROM recipe_ingredient WHERE recipe_id IN (SELECT id FROM recipe WHERE owner_id = ?)', [$id]);
                        $db->executeStatement('DELETE FROM recipe WHERE owner_id = ?', [$id]);
                        $db->executeStatement('DELETE FROM "user" WHERE id = ?', [$id]);
                    }
                    foreach ($this->ingredientIds as $id) {
                        $db->executeStatement('DELETE FROM ingredient WHERE id = ?', [$id]);
                    }
                });
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testRecipeLifecycleAndSharedIngredients(): void
    {
        $this->request('GET', '/api/recipes');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->body());

        $payload = $this->payload();
        $payload['name'] = '  Rice and beans  ';
        $payload['instructions'] = '  Cook together.  ';
        $payload['owner_id'] = $this->userIds[1];
        $payload['owner'] = $this->userIds[1];
        $payload['userId'] = $this->userIds[1];
        $this->request('POST', '/api/recipes', $payload);
        self::assertResponseStatusCodeSame(201);
        $created = $this->body();
        $id = $created['id'];
        self::assertResponseHeaderSame('Location', '/api/recipes/'.$id);
        self::assertSame(['id', 'name', 'servings', 'instructions', 'ingredients'], array_keys($created));
        self::assertSame('Rice and beans', $created['name']);
        self::assertSame('Cook together.', $created['instructions']);
        self::assertCount(2, $created['ingredients']);
        self::assertSame('150.000', $created['ingredients'][0]['quantity']);
        self::assertSame('g', $created['ingredients'][0]['unit']);
        self::assertSame($this->userIds[0], $this->storedOwner($id));

        $this->request('GET', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($created, $this->body());
        $this->request('GET', '/api/recipes');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$created], $this->body());

        // A second owned recipe reuses rice with a different amount.
        $second = $this->payload();
        $second['ingredients'] = [$this->assignment(0, '250.125')];
        $this->request('POST', '/api/recipes', $second);
        self::assertResponseStatusCodeSame(201);
        $secondId = $this->body()['id'];

        // Retain and change rice, remove beans, and add salt in one flush.
        $updated = $this->payload();
        $updated['name'] = 'Changed recipe';
        $updated['servings'] = 4;
        $updated['instructions'] = 'New instructions';
        $updated['owner_id'] = $this->userIds[1];
        $updated['ingredients'] = [$this->assignment(0, '9999999.999'), $this->assignment(2, '0.001')];
        $this->request('PUT', '/api/recipes/'.$id, $updated);
        self::assertResponseStatusCodeSame(200);
        $replacement = $this->body();
        self::assertSame('Changed recipe', $replacement['name']);
        self::assertSame(4, $replacement['servings']);
        self::assertSame('New instructions', $replacement['instructions']);
        self::assertSame('9999999.999', $replacement['ingredients'][0]['quantity']);
        self::assertSame($this->ingredientIds[2], $replacement['ingredients'][1]['ingredientId']);
        self::assertSame($this->userIds[0], $this->storedOwner($id));
        $this->request('GET', '/api/recipes/'.$id);
        self::assertSame($replacement, $this->body());
        self::assertSame(2, $this->linkCount($id));
        $this->request('GET', '/api/recipes/'.$secondId);
        self::assertSame('250.125', $this->body()['ingredients'][0]['quantity']);

        // PUT replaces the whole representation; omitted ingredients clears links.
        unset($updated['ingredients']);
        $this->request('PUT', '/api/recipes/'.$id, $updated);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->body()['ingredients']);
        self::assertSame(0, $this->linkCount($id));
        $this->request('PUT', '/api/recipes/'.$id, $payload);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->linkCount($id));

        $this->request('DELETE', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->linkCount($id));
        self::assertNull($this->storedOwner($id));
        foreach ($this->ingredientIds as $ingredientId) {
            self::assertNotFalse($this->db()->fetchOne('SELECT id FROM ingredient WHERE id = ?', [$ingredientId]));
        }
        $this->request('GET', '/api/recipes/'.$secondId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('250.125', $this->body()['ingredients'][0]['quantity']);
        $this->request('DELETE', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBasicCreationWithoutIngredients(): void
    {
        $payload = $this->payload();
        unset($payload['ingredients']);
        $payload['name'] = str_repeat('é', 150);
        $this->request('POST', '/api/recipes', $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($payload['name'], $this->body()['name']);
        self::assertSame([], $this->body()['ingredients']);
    }

    public function testOwnershipAndUnknownIds(): void
    {
        $this->request('POST', '/api/recipes', $this->payload());
        $created = $this->body();
        $id = $created['id'];
        $this->request('POST', '/api/recipes', $this->payload(), $this->tokens[1]);
        self::assertResponseStatusCodeSame(201);
        $otherRecipe = $this->body();
        $this->request('GET', '/api/recipes', token: $this->tokens[1]);
        self::assertSame([$otherRecipe], $this->body());

        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->request($method, '/api/recipes/'.$id, $this->payload(), $this->tokens[1]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => 'Recipe not found'], $this->body());
            foreach (['2147483647', '2147483648', '99999999999999999999', 'abc', '0', '-1', '1.2'] as $missing) {
                $this->request($method, '/api/recipes/'.$missing, $this->payload());
                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => 'Recipe not found'], $this->body());
            }
        }
        $this->request('GET', '/api/recipes/'.$id);
        self::assertSame($created, $this->body());
        self::assertSame($this->userIds[0], $this->storedOwner($id));
    }

    public function testEveryRouteRequiresValidUnexpiredJwt(): void
    {
        $this->request('POST', '/api/recipes', $this->payload());
        $created = $this->body();
        foreach ([['POST', '/api/recipes'], ['GET', '/api/recipes'], ['GET', '/api/recipes/'.$created['id']],
            ['PUT', '/api/recipes/'.$created['id']], ['DELETE', '/api/recipes/'.$created['id']]] as [$method, $url]) {
            foreach (['', 'invalid.token.value', $this->expiredToken] as $token) {
                $this->request($method, $url, $this->payload(), $token);
                self::assertResponseStatusCodeSame(401);
            }
        }
        $this->request('GET', '/api/recipes');
        self::assertSame([$created], $this->body());
    }

    public function testInvalidPayloadsDoNotCreateOrMutateRecipes(): void
    {
        $this->request('POST', '/api/recipes', $this->payload());
        $original = $this->body();
        $invalid = [[], ['name' => null], ['name' => false], ['name' => []], ['name' => ' '],
            ['name' => str_repeat('é', 151)], ['servings' => null], ['servings' => '2'], ['servings' => 2.5],
            ['servings' => true], ['servings' => []], ['servings' => 0], ['servings' => -1],
            ['servings' => 2147483648], ['instructions' => null], ['instructions' => []], ['instructions' => ' '],
            ['ingredients' => null], ['ingredients' => 'rice'], ['ingredients' => ['wrong' => []]],
            ['ingredients' => [null]], ['ingredients' => [[]]]];
        foreach (['ingredientId' => [null, '1', 0, -1, true, [], 2147483648, 2147483647],
            'quantity' => [null, 1, 1.5, true, [], '', '0', '0.000', '-1', '1e3', '1.0001', '10000000', '01', ' 1 '],
            'unit' => [null, true, [], '', ' ', str_repeat('u', 21)]] as $field => $values) {
            foreach ($values as $value) {
                $invalid[] = ['ingredients' => [array_replace($this->assignment(0), [$field => $value])]];
            }
        }
        foreach ($invalid as $index => $changes) {
            $payload = $index === 0 ? [] : array_replace($this->payload(), $changes);
            foreach ([['POST', '/api/recipes'], ['PUT', '/api/recipes/'.$original['id']]] as [$method, $url]) {
                $this->request($method, $url, $payload);
                self::assertResponseStatusCodeSame(400);
                self::assertIsString($this->body()['error']);
            }
        }
        $this->request('GET', '/api/recipes');
        self::assertSame([$original], $this->body());
        self::assertSame(2, $this->linkCount($original['id']));
    }

    public function testMalformedJsonAndDuplicateAssignmentsAreControlled(): void
    {
        $this->request('POST', '/api/recipes', $this->payload());
        $original = $this->body();
        foreach ([['POST', '/api/recipes'], ['PUT', '/api/recipes/'.$original['id']]] as [$method, $url]) {
            foreach (['', '{', 'null', 'true', '"text"', '[]'] as $body) {
                $this->request($method, $url, $body);
                self::assertResponseStatusCodeSame(400);
                self::assertIsString($this->body()['error']);
            }
            $duplicate = $this->payload();
            $duplicate['ingredients'] = [$this->assignment(0), $this->assignment(0, '2')];
            $this->request($method, $url, $duplicate);
            self::assertResponseStatusCodeSame(409);
            self::assertIsString($this->body()['error']);
        }
        $this->request('GET', '/api/recipes');
        self::assertSame([$original], $this->body());
    }

    private function request(string $method, string $url, array|string|null $payload = null, ?string $token = null): void
    {
        $token ??= $this->tokens[0];
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $url, server: $server,
            content: is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload);
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function payload(): array
    {
        return ['name' => 'Rice and beans', 'servings' => 2, 'instructions' => 'Cook together.',
            'ingredients' => [$this->assignment(0), $this->assignment(1, '20.5')]];
    }

    private function assignment(int $index, string $quantity = '150'): array
    {
        return ['ingredientId' => $this->ingredientIds[$index], 'quantity' => $quantity, 'unit' => ' g '];
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function storedOwner(int $id): ?int
    {
        $owner = $this->db()->fetchOne('SELECT owner_id FROM recipe WHERE id = ?', [$id]);

        return $owner === false ? null : (int) $owner;
    }

    private function linkCount(int $id): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe_ingredient WHERE recipe_id = ?', [$id]);
    }
}
