<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if(!$user instanceof User){
           return $this->json(
            ['error'=> 'Unauthorized'],
            Response::HTTP_UNAUTHORIZED
           );
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail()
        ]);
    }
}
