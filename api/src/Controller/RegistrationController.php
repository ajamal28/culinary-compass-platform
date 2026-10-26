<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;


final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, ValidatorInterface $validator, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (JsonException $e) {
            return $this->json(
                ['error'=> "Inavlid JSON request body"],
                Response::HTTP_BAD_REQUEST
             );
        }

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (!is_string($email) || !is_string($password)|| trim($email) === '' || trim($password) === '') {
            return $this->json(
                ['error'=> "Email and password are required"],
                Response::HTTP_BAD_REQUEST
             );
        }

        $email = strtolower(trim($email));

        # Validate email format
        $violations = $validator->validate($email, [
            new Assert\Email(),
        ]);
        if (count($violations) > 0) {
            return $this->json(
                ['error'=> "Please enter a valid email address"],
                Response::HTTP_BAD_REQUEST
            );
        }

        # Validate password length (minimum 12 characters)
        $passwordViolations = $validator->validate($password, [
            new Assert\Length(min: 12),
        ]);
        if (count($passwordViolations) > 0) {
            return $this->json(
                ['error'=> "Password must be at least 12 characters long"],
                Response::HTTP_BAD_REQUEST
            );

        }

        # Check if the email is already registered
        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null) {
            return $this->json(
                ['error'=> "Email address is already registered"],
                Response::HTTP_CONFLICT
            );

        }

        # Create a new user and save it to the database with hashed password
        $user = new User();
        $user->setEmail($email);

        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(
            ['message' => 'User registered successfully',
            "id" => $user->getId(),],
            Response::HTTP_CREATED
        );

    }
}
