<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiHealthControllerTest extends WebTestCase
{
    public function testHealthCheck(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health');
        self::assertResponseStatusCodeSame(200);
        // Assert the status if equals to ok
        $responseData = json_decode($client->getResponse()->getContent(), true,512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $responseData['status']);

    }
}
