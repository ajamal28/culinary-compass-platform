<?php

namespace App\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;


final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, ValidatorInterface $validator): JsonResponse
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
        $violations = $validator->validate($email, [
            new Assert\Email(),
        ]);

        if (count($violations) > 0) {
            return $this->json(
                ['error'=> "Please enter a valid email address"],
                Response::HTTP_BAD_REQUEST
            );
        }

        $passwordViolations = $validator->validate($password, [
            new Assert\Length(min: 12),
        ]);

        if (count($passwordViolations) > 0) {
            return $this->json(
                ['error'=> "Password must be at least 12 characters long"],
                Response::HTTP_BAD_REQUEST
            );

        }

        return $this->json(
            ['message' => 'Registration endpoint working'],
            Response::HTTP_OK
        );
    }
}
