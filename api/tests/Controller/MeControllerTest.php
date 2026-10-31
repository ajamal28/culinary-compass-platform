<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeControllerTest extends WebTestCase
{
    public function testRejectsRequestWithoutToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsInvalidToken(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/me',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer invalid.token.value',
            ]
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testReturnsUserWithValidToken(): void
    {
        $client = static::createClient();

        $email = 'jwt-' . bin2hex(random_bytes(8)) . '@example.com';
        $password = 'SecurePassword123!';

        try {
            // Register a temporary user.
            $client->request(
                'POST',
                '/api/register',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'email' => $email,
                    'password' => $password,
                ], JSON_THROW_ON_ERROR)
            );

            self::assertResponseStatusCodeSame(201);

            // Log in and obtain the JWT.
            $client->request(
                'POST',
                '/api/login_check',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'email' => $email,
                    'password' => $password,
                ], JSON_THROW_ON_ERROR)
            );

            self::assertResponseStatusCodeSame(200);

            $loginData = json_decode(
                $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertArrayHasKey('token', $loginData);
            self::assertNotEmpty($loginData['token']);

            // Access the protected endpoint.
            $client->request(
                'GET',
                '/api/me',
                server: [
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $loginData['token'],
                ]
            );

            self::assertResponseStatusCodeSame(200);

            $profile = json_decode(
                $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame($email, $profile['email']);
            self::assertIsInt($profile['id']);

        } finally {
            $this->deleteTestUser($email);
        }
    }

    public function testRejectsIncorrectPassword(): void
    {
        $client = static::createClient();

        $email = 'wrong-' . bin2hex(random_bytes(8)) . '@example.com';

        try {
            $client->request(
                'POST',
                '/api/register',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'email' => $email,
                    'password' => 'SecurePassword123!',
                ], JSON_THROW_ON_ERROR)
            );

            self::assertResponseStatusCodeSame(201);

            $client->request(
                'POST',
                '/api/login_check',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'email' => $email,
                    'password' => 'DefinitelyWrong123!',
                ], JSON_THROW_ON_ERROR)
            );

            self::assertResponseStatusCodeSame(401);

        } finally {
            $this->deleteTestUser($email);
        }
    }

    private function deleteTestUser(string $email): void
    {
        $userRepository = static::getContainer()->get(
            UserRepository::class
        );

        $user = $userRepository->findOneBy(['email' => $email]);

        if ($user !== null) {
            $entityManager = static::getContainer()->get(
                EntityManagerInterface::class
            );

            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}
