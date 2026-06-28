<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller\Api;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->conn = $container->get(EntityManagerInterface::class)->getConnection();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = (new User())->setUsername('healthuser')->setEmail('health@test.com')->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    public function testRequiresAuth(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertResponseStatusCodeSame(401);
    }

    public function testReportsRecentCollectionAge(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO app_state (name, value, updated_at) VALUES (:n, :v, :u)'
            .' ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'n' => 'last_collection_at',
                'v' => (new \DateTimeImmutable('-10 minutes'))->format(\DateTimeInterface::ATOM),
                'u' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $this->client->request('GET', '/api/health', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotNull($data['lastCollectedAt']);
        self::assertGreaterThanOrEqual(9, $data['ageMinutes']);
        self::assertLessThanOrEqual(11, $data['ageMinutes']);
    }

    public function testNullWhenNoCollectionYet(): void
    {
        $this->conn->executeStatement("DELETE FROM app_state WHERE name = 'last_collection_at'");

        $this->client->request('GET', '/api/health', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNull($data['lastCollectedAt']);
        self::assertNull($data['ageMinutes']);
    }
}
