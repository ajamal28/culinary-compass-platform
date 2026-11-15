<?php

namespace App\Tests\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Repository\IngredientRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IngredientControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $userIds = [];
    private array $ingredientIds = [];
    private array $tokens = [];
    private string $expiredToken;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        self::assertSame('culinary_compass_test', $this->db()->fetchOne('SELECT current_database()'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(8));
        foreach (['first', 'second'] as $label) {
            $user = (new User())->setEmail('catalogue-'.$label.'-'.$this->suffix.'@example.com')->setPassword('unused-test-hash');
            $em->persist($user);
            $em->flush();
            $this->userIds[] = $user->getId();
            $this->tokens[] = $jwt->create($user);
            $this->expiredToken = $jwt->createFromPayload($user, ['iat' => time() - 7200, 'exp' => time() - 3600]);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== [] || $this->ingredientIds !== []) {
                $db = $this->db();
                self::assertSame('culinary_compass_test', $db->fetchOne('SELECT current_database()'));
                $db->transactional(function (Connection $db): void {
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

    public function testCreateReturnsSharedIngredientUsableInPrivateRecipes(): void
    {
        $this->request('POST', '/api/ingredients', ['name' => "  Basmati\t  Rice ".$this->suffix.'  ', 'owner_id' => $this->userIds[0]]);
        self::assertResponseStatusCodeSame(201);
        $ingredient = $this->body();
        self::assertSame(['id', 'name'], array_keys($ingredient));
        self::assertSame('Basmati Rice '.$this->suffix, $ingredient['name']);
        self::assertIsInt($ingredient['id']);
        self::assertResponseHeaderSame('Location', '/api/ingredients/'.$ingredient['id']);
        $this->request('GET', '/api/ingredients/'.$ingredient['id'], token: $this->tokens[1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($ingredient, $this->body());

        $recipeIds = [];
        foreach ($this->tokens as $index => $token) {
            $this->request('POST', '/api/recipes', ['name' => 'Private rice', 'servings' => 2, 'instructions' => 'Cook.',
                'ingredients' => [['ingredientId' => $ingredient['id'], 'quantity' => (string) (150 + $index), 'unit' => 'g']]], $token);
            self::assertResponseStatusCodeSame(201);
            self::assertSame($ingredient['id'], $this->body()['ingredients'][0]['ingredientId']);
            $recipeIds[] = $this->body()['id'];
        }
        $this->request('GET', '/api/recipes/'.$recipeIds[0], token: $this->tokens[1]);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', '/api/recipes/'.$recipeIds[0]);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/ingredients/'.$ingredient['id']);
        self::assertSame($ingredient, $this->body());
        $this->request('GET', '/api/recipes/'.$recipeIds[1], token: $this->tokens[1]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testDuplicateNamesUseCaseAndUnicodeWhitespaceNormalizationAcrossUsers(): void
    {
        $this->request('POST', '/api/ingredients', ['name' => 'Épinards Rice '.$this->suffix]);
        self::assertResponseStatusCodeSame(201);
        $original = $this->body();
        foreach (['éPINARDS RICE '.$this->suffix, " épinards\u{00a0}\t rice  ".$this->suffix.' '] as $name) {
            $this->request('POST', '/api/ingredients', ['name' => $name], $this->tokens[1]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => 'Ingredient already exists'], $this->body());
        }
        $this->request('GET', '/api/ingredients?q='.$this->suffix);
        self::assertSame([$original], $this->body());
    }

    public function testUniqueConstraintProtectsIndependentDatabaseWriters(): void
    {
        $this->request('POST', '/api/ingredients', ['name' => 'Rice '.$this->suffix]);
        self::assertResponseStatusCodeSame(201);
        $db = $this->db();
        $db->beginTransaction();
        try {
            $this->expectException(UniqueConstraintViolationException::class);
            $db->insert('ingredient', ['name' => 'RICE '.$this->suffix, 'canonical_name' => 'rice '.$this->suffix]);
        } finally {
            $db->rollBack();
        }
    }

    public function testLiteralSearchOrderingAndLimits(): void
    {
        foreach (['Zulu Rice ', 'Alpha Rice ', 'Middle Rice ', '100% Rice ', 'Under_score Rice ', 'Bang! Rice '] as $prefix) {
            $this->request('POST', '/api/ingredients', ['name' => $prefix.$this->suffix]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->request('GET', '/api/ingredients?'.http_build_query(['q' => 'RICE '.$this->suffix, 'limit' => '2']), token: $this->tokens[1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['100% Rice '.$this->suffix, 'Alpha Rice '.$this->suffix], array_column($this->body(), 'name'));
        foreach (['%', '_', '!'] as $literal) {
            $this->request('GET', '/api/ingredients?'.http_build_query(['q' => $literal]));
            self::assertResponseStatusCodeSame(200);
            $matching = array_filter($this->body(), fn (array $item): bool => str_contains($item['name'], $this->suffix));
            self::assertCount(1, $matching);
            self::assertStringContainsString($literal, array_values($matching)[0]['name']);
        }
        $this->request('GET', '/api/ingredients?q=missing-'.$this->suffix);
        self::assertSame([], $this->body());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $seeded = [];
        for ($index = 0; $index < 21; ++$index) {
            $ingredient = (new Ingredient())->setName('Bound '.$index.' '.$this->suffix);
            $em->persist($ingredient);
            $seeded[] = $ingredient;
        }
        $em->flush();
        foreach ($seeded as $ingredient) {
            $this->ingredientIds[] = $ingredient->getId();
        }
        foreach (['' => 20, '?q=' => 20, '?q=%20%20&limit=1' => 1] as $query => $expected) {
            $this->request('GET', '/api/ingredients'.$query);
            self::assertResponseStatusCodeSame(200);
            self::assertCount($expected, $this->body());
        }
        $this->request('GET', '/api/ingredients?limit=100');
        self::assertResponseStatusCodeSame(200);
        self::assertLessThanOrEqual(100, count($this->body()));
    }

    public function testAConcurrentDuplicateAfterPrecheckReturnsConflict(): void
    {
        $name = 'Concurrent Rice '.$this->suffix;
        $this->request('POST', '/api/ingredients', ['name' => $name]);
        self::assertResponseStatusCodeSame(201);
        $original = $this->body();
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        // Deterministically model a stale pre-check: another writer has already
        // committed the duplicate by the time this request reaches flush().
        $repository = $this->createMock(IngredientRepository::class);
        $repository->expects(self::once())->method('findOneBy')->willReturn(null);
        static::getContainer()->set(IngredientRepository::class, $repository);
        $this->request('POST', '/api/ingredients', ['name' => mb_strtoupper($name, 'UTF-8')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Ingredient already exists'], $this->body());
        $this->request('GET', '/api/ingredients/'.$original['id']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($original, $this->body());
    }

    public function testInvalidNamesAndMalformedBodiesAreRejected(): void
    {
        foreach ([null, true, false, 2, 1.5, [], '', ' ', "\u{00a0}", str_repeat('é', 151), "rice\0", "ri\u{200b}ce"] as $name) {
            $this->request('POST', '/api/ingredients', ['name' => $name]);
            self::assertResponseStatusCodeSame(400);
            self::assertIsString($this->body()['error']);
        }
        foreach (['', '{', '{}', '[]', 'null', 'true', '"rice"'] as $body) {
            $this->request('POST', '/api/ingredients', $body);
            self::assertResponseStatusCodeSame(400);
            self::assertIsString($this->body()['error']);
        }
        self::assertSame([], $this->ingredientIds);
        $this->request('POST', '/api/ingredients', ['name' => str_repeat('é', 150)]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('é', 150), $this->body()['name']);
    }

    public function testInvalidSearchParametersAndIds(): void
    {
        foreach (['?q[]=rice', '?limit[]=2', '?limit=0', '?limit=-1', '?limit=101', '?limit=1.5', '?limit=01',
            '?limit=', '?limit=bad', '?q='.str_repeat('a', 151), '?q=rice%00', '?q=%FF'] as $query) {
            $this->request('GET', '/api/ingredients'.$query);
            self::assertResponseStatusCodeSame(400);
            self::assertIsString($this->body()['error']);
        }
        foreach (['abc', '-1', '0', '2147483647', '2147483648', '99999999999999999999'] as $id) {
            $this->request('GET', '/api/ingredients/'.$id);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => 'Ingredient not found'], $this->body());
        }
    }

    public function testJwtIsRequiredAndNoGlobalMutationRoutesExist(): void
    {
        foreach ([['GET', '/api/ingredients'], ['POST', '/api/ingredients'], ['GET', '/api/ingredients/1']] as [$method, $url]) {
            foreach (['', 'invalid.token.value', $this->expiredToken] as $token) {
                $this->request($method, $url, ['name' => 'Unauthorized '.$this->suffix], $token);
                self::assertResponseStatusCodeSame(401);
            }
        }
        self::assertSame([], $this->ingredientIds);
        $this->request('POST', '/api/ingredients', ['name' => 'Protected '.$this->suffix]);
        self::assertResponseStatusCodeSame(201);
        $original = $this->body();
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->request($method, '/api/ingredients/'.$original['id'], ['name' => 'Renamed']);
            self::assertResponseStatusCodeSame(405);
        }
        $this->request('GET', '/api/ingredients/'.$original['id']);
        self::assertSame($original, $this->body());
    }

    private function request(string $method, string $url, array|string|null $data = null, ?string $token = null): void
    {
        $token ??= $this->tokens[0];
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $url, server: $server,
            content: is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR) : $data);
        if ($method === 'POST' && $url === '/api/ingredients' && $this->client->getResponse()->getStatusCode() === 201) {
            $this->ingredientIds[] = $this->body()['id'];
        }
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
