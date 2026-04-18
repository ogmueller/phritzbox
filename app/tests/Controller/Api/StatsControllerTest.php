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

use App\Entity\SmartDeviceData;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class StatsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setUsername('statsuser')
            ->setEmail('stats@test.com')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $jwt = $container->get(JWTTokenManagerInterface::class);
        $this->token = $jwt->create($user);
    }

    public function testShowStatsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/stats/12345');
        self::assertResponseStatusCodeSame(401);
    }

    public function testShowStatsEmpty(): void
    {
        $this->client->request('GET', '/api/stats/nonexistent?type=temperature', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('nonexistent', $data['ain']);
        self::assertEmpty($data['data']);
    }

    public function testShowStatsWithData(): void
    {
        // Insert test data
        $entry = new SmartDeviceData();
        $entry->setSid('test-ain-001')
            ->setType('temperature')
            ->setValue(215)
            ->setTime(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($entry);

        $entry2 = new SmartDeviceData();
        $entry2->setSid('test-ain-001')
            ->setType('temperature')
            ->setValue(220)
            ->setTime(new \DateTimeImmutable('-30 minutes'));
        $this->em->persist($entry2);
        $this->em->flush();

        $this->client->request('GET', '/api/stats/test-ain-001?type=temperature', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('test-ain-001', $data['ain']);
        self::assertCount(2, $data['data']);
        self::assertSame('temperature', $data['data'][0]['type']);
    }

    public function testShowStatsWithDateRange(): void
    {
        $entry = new SmartDeviceData();
        $entry->setSid('test-ain-002')
            ->setType('power')
            ->setValue(5000)  // 50W in cW
            ->setTime(new \DateTimeImmutable('2026-04-15 12:00:00'));
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->request('GET', '/api/stats/test-ain-002?type=power&from=2026-04-15&to=2026-04-15', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['data']);
        // power: cW → W (÷100) = 50
        self::assertEquals(50.0, $data['data'][0]['value']);
    }

    public function testShowStatsVoltageConversion(): void
    {
        $entry = new SmartDeviceData();
        $entry->setSid('test-ain-003')
            ->setType('voltage')
            ->setValue(230000)  // 230V in mV
            ->setTime(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->request('GET', '/api/stats/test-ain-003?type=voltage', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['data']);
        // voltage: mV → V (÷1000) = 230
        self::assertEquals(230.0, $data['data'][0]['value']);
    }

    public function testTypesEndpoint(): void
    {
        $entry = new SmartDeviceData();
        $entry->setSid('test-ain-004')
            ->setType('temperature')
            ->setValue(200)
            ->setTime(new \DateTimeImmutable());
        $this->em->persist($entry);

        $entry2 = new SmartDeviceData();
        $entry2->setSid('test-ain-004')
            ->setType('power')
            ->setValue(100)
            ->setTime(new \DateTimeImmutable());
        $this->em->persist($entry2);
        $this->em->flush();

        $this->client->request('GET', '/api/stats/types/test-ain-004', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('test-ain-004', $data['ain']);
        self::assertContains('temperature', $data['types']);
        self::assertContains('power', $data['types']);
    }
}
