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
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ChannelControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $jwt = $container->get(JWTTokenManagerInterface::class);

        $admin = (new User())->setUsername('chanadmin')->setEmail('ca@test.com')->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pass'));
        $this->em->persist($admin);
        $this->em->flush();
        $this->adminToken = $jwt->create($admin);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: $body !== null ? json_encode($body) : null);
        $content = $this->client->getResponse()->getContent();

        return [$this->client->getResponse()->getStatusCode(), $content ? json_decode($content, true) : null];
    }

    public function testCrudAndValidation(): void
    {
        // invalid email target rejected
        [$status] = $this->request('POST', '/api/channels', ['name' => 'x', 'type' => 'email', 'target' => 'not-an-email']);
        self::assertSame(400, $status);

        // valid webhook channel
        [$status, $created] = $this->request('POST', '/api/channels', ['name' => 'Hook', 'type' => 'webhook', 'target' => 'https://example.test/h']);
        self::assertSame(201, $status);
        self::assertSame('Hook', $created['name']);
        $id = $created['id'];

        [$status, $list] = $this->request('GET', '/api/channels');
        self::assertSame(200, $status);
        self::assertContains($id, array_column($list, 'id'));

        [$status, $updated] = $this->request('PUT', '/api/channels/'.$id, ['name' => 'Hook2', 'type' => 'webhook', 'target' => 'https://example.test/h2']);
        self::assertSame(200, $status);
        self::assertSame('Hook2', $updated['name']);

        [$status] = $this->request('DELETE', '/api/channels/'.$id);
        self::assertSame(204, $status);
    }

    public function testPushoverRequiresSecret(): void
    {
        // missing application token
        [$status] = $this->request('POST', '/api/channels', ['name' => 'po', 'type' => 'pushover', 'target' => 'userkey']);
        self::assertSame(400, $status);

        // valid: user key in target + app token in secret
        [$status, $created] = $this->request('POST', '/api/channels', [
            'name' => 'Phone', 'type' => 'pushover', 'target' => 'uQ123userkey', 'secret' => 'aTok3napp',
        ]);
        self::assertSame(201, $status);
        self::assertSame('pushover', $created['type']);
        self::assertSame('aTok3napp', $created['secret']);
    }

    public function testTelegramRequiresSecret(): void
    {
        [$status] = $this->request('POST', '/api/channels', ['name' => 'tg', 'type' => 'telegram', 'target' => '12345']);
        self::assertSame(400, $status);

        [$status, $created] = $this->request('POST', '/api/channels', [
            'name' => 'tg', 'type' => 'telegram', 'target' => '12345', 'secret' => 'bottoken',
        ]);
        self::assertSame(201, $status);
        self::assertSame('telegram', $created['type']);
    }

    public function testDiscordWebhookUrlValidated(): void
    {
        [$status] = $this->request('POST', '/api/channels', ['name' => 'd', 'type' => 'discord', 'target' => 'not-a-url']);
        self::assertSame(400, $status);

        [$status] = $this->request('POST', '/api/channels', [
            'name' => 'd', 'type' => 'discord', 'target' => 'https://discord.com/api/webhooks/1/abc',
        ]);
        self::assertSame(201, $status);
    }

    public function testCannotDeleteChannelInUse(): void
    {
        [, $channel] = $this->request('POST', '/api/channels', ['name' => 'Used', 'type' => 'webhook', 'target' => 'https://example.test/u']);
        [, $rule] = $this->request('POST', '/api/alerts', [
            'name' => 'r', 'enabled' => true, 'mode' => 'threshold', 'sid' => 'ain', 'type' => 'temperature',
            'operator' => 'gt', 'threshold' => 5, 'channelIds' => [$channel['id']], 'durationMinutes' => 0, 'cooldownMinutes' => 60,
        ]);
        self::assertNotNull($rule['id']);

        [$status, $body] = $this->request('DELETE', '/api/channels/'.$channel['id']);
        self::assertSame(409, $status);
        self::assertStringContainsString('alert rule', $body['error']);
    }
}
