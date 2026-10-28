<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRejectsInvalidEmail(): void
    {
        $client = static::createClient();

        $client->request(
            "POST",
            "/api/register",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'invalid-email',
                'password' => 'validpassword123'
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(400);

        $responseData = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('Please enter a valid email address', $responseData['error']);
    }

    public function testRejectsShortPassword(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'test@example.com',
                'password' => 'short'
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(400);

        $responseData = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            'Password must be at least 12 characters long',
            $responseData['error']
        );
    }

    public function testRegistersUserSuccessfully(): void
    {
        $client = static::createClient();

        $email = 'test-'. bin2hex(random_bytes(8)) .'@example.com';
        $password = 'securepassword123!';

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => $email,
                'password' => $password
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $userPassword = $user->getPassword();
        self::assertNotSame($password, $userPassword);

        // Check if the password is hashed
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, $password));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($user);
        $entityManager->flush();
    }

    public function testRejectsDuplicateEmail(): void
    {
        $client = static::createClient();
        $email = 'duplicate-'. bin2hex(random_bytes(8)) .'@example.com';
        $password = 'securepassword123!';

        $body = json_encode([
            'email' => $email,
            'password' => $password,
        ], JSON_THROW_ON_ERROR);

        try{
            $client->request(
                'POST',
                '/api/register',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $body
            );

            self::assertResponseStatusCodeSame(201);

            $client->request(
                'POST',
                '/api/register',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $body
            );

            self::assertResponseStatusCodeSame(409);

            $responseData = json_decode(
                $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame(
                'Email address is already registered',
                $responseData['error']
            );

        } finally {
            $userRepository = static::getContainer()->get(UserRepository::class);
            $user = $userRepository->findOneBy(['email' => $email]);

            if ($user !== null) {
                $entityManager = static::getContainer()->get(EntityManagerInterface::class);

                $entityManager->remove($user);
                $entityManager->flush();
            }
        }

    }

    public function testRejectsNonStringEmail(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => ['invalid', 'email'],
                'password' => 'securepassword123!'
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(400);
    }
}
