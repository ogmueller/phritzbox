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

use App\Entity\AlertRule;
use App\Entity\NotificationChannel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AlertControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminToken;
    private string $userToken;
    private int $channelId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $jwt = $container->get(JWTTokenManagerInterface::class);

        $admin = (new User())->setUsername('alertadmin')->setEmail('aa@test.com')->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pass'));
        $this->em->persist($admin);

        $user = (new User())->setUsername('alertuser')->setEmail('au@test.com')->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $channel = (new NotificationChannel())->setName('Admin mail')->setType('email')->setTarget('me@example.test');
        $this->em->persist($channel);
        $this->em->flush();
        $this->channelId = $channel->getId();

        $this->adminToken = $jwt->create($admin);
        $this->userToken = $jwt->create($user);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?string $token, ?array $body = null): array
    {
        $server = $token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'] : [];
        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        $content = $this->client->getResponse()->getContent();

        return [$this->client->getResponse()->getStatusCode(), $content ? json_decode($content, true) : null];
    }

    private function validPayload(): array
    {
        return [
            'name' => 'Too hot', 'enabled' => true, 'mode' => 'threshold',
            'sid' => 'ain-1', 'type' => 'temperature', 'operator' => 'gt', 'threshold' => 22,
            'channelIds' => [$this->channelId],
            'durationMinutes' => 0, 'cooldownMinutes' => 60,
        ];
    }

    public function testRequiresAuth(): void
    {
        [$status] = $this->request('GET', '/api/alerts', null);
        self::assertSame(401, $status);
    }

    public function testRequiresAdmin(): void
    {
        [$status] = $this->request('GET', '/api/alerts', $this->userToken);
        self::assertSame(403, $status);
    }

    public function testCrudLifecycle(): void
    {
        [$status, $created] = $this->request('POST', '/api/alerts', $this->adminToken, $this->validPayload());
        self::assertSame(201, $status);
        self::assertSame('Too hot', $created['name']);
        self::assertSame('ok', $created['lastState']);
        self::assertSame([$this->channelId], $created['channelIds']);
        $id = $created['id'];

        [$status, $list] = $this->request('GET', '/api/alerts', $this->adminToken);
        self::assertSame(200, $status);
        self::assertContains($id, array_column($list, 'id'));

        [$status, $updated] = $this->request('PUT', '/api/alerts/'.$id, $this->adminToken, ['threshold' => 30] + $this->validPayload());
        self::assertSame(200, $status);
        self::assertEquals(30, $updated['threshold']);

        [$status] = $this->request('DELETE', '/api/alerts/'.$id, $this->adminToken);
        self::assertSame(204, $status);

        // Deleting again on the now-missing id returns 404.
        [$status] = $this->request('DELETE', '/api/alerts/'.$id, $this->adminToken);
        self::assertSame(404, $status);
    }

    public function testValidationErrors(): void
    {
        $bad = $this->validPayload();
        unset($bad['name']);
        [$status, $body] = $this->request('POST', '/api/alerts', $this->adminToken, $bad);
        self::assertSame(400, $status);
        self::assertArrayHasKey('error', $body);

        $bad = $this->validPayload();
        $bad['type'] = 'bogus';
        [$status] = $this->request('POST', '/api/alerts', $this->adminToken, $bad);
        self::assertSame(400, $status);

        // comparison mode requires compareSid
        $bad = ['name' => 'cmp', 'enabled' => true, 'mode' => 'comparison', 'sid' => 'a', 'type' => 'temperature',
            'operator' => 'gt', 'channelIds' => [$this->channelId], 'durationMinutes' => 0, 'cooldownMinutes' => 60];
        [$status] = $this->request('POST', '/api/alerts', $this->adminToken, $bad);
        self::assertSame(400, $status);

        // at least one channel is required
        $bad = $this->validPayload();
        $bad['channelIds'] = [];
        [$status] = $this->request('POST', '/api/alerts', $this->adminToken, $bad);
        self::assertSame(400, $status);
    }

    public function testMultipleChannels(): void
    {
        $second = (new NotificationChannel())->setName('Webhook')->setType('webhook')->setTarget('https://example.test/h');
        $this->em->persist($second);
        $this->em->flush();

        $payload = $this->validPayload();
        $payload['channelIds'] = [$this->channelId, $second->getId()];
        [$status, $created] = $this->request('POST', '/api/alerts', $this->adminToken, $payload);
        self::assertSame(201, $status);
        self::assertEqualsCanonicalizing([$this->channelId, $second->getId()], $created['channelIds']);
        self::assertCount(2, $created['channelNames']);
    }

    public function testToggleFlipsEnabled(): void
    {
        [, $created] = $this->request('POST', '/api/alerts', $this->adminToken, $this->validPayload());
        self::assertTrue($created['enabled']);

        [$status, $off] = $this->request('POST', '/api/alerts/'.$created['id'].'/toggle', $this->adminToken);
        self::assertSame(200, $status);
        self::assertFalse($off['enabled']);

        [, $on] = $this->request('POST', '/api/alerts/'.$created['id'].'/toggle', $this->adminToken);
        self::assertTrue($on['enabled']);
    }

    public function testRearmResetsTriggeredRuleToOk(): void
    {
        [, $created] = $this->request('POST', '/api/alerts', $this->adminToken, $this->validPayload());

        // Latch the rule into the triggered state, as a real firing would.
        $rule = $this->em->getRepository(AlertRule::class)->find($created['id']);
        $rule->setLastState(AlertRule::STATE_TRIGGERED);
        $this->em->flush();

        [$status, $body] = $this->request('POST', '/api/alerts/'.$created['id'].'/rearm', $this->adminToken);
        self::assertSame(200, $status);
        self::assertSame('ok', $body['lastState']);
    }

    public function testTestEndpointSendsThroughChannel(): void
    {
        [, $created] = $this->request('POST', '/api/alerts', $this->adminToken, $this->validPayload());

        // email channel + null mailer DSN in test env => no-op, succeeds
        [$status, $body] = $this->request('POST', '/api/alerts/'.$created['id'].'/test', $this->adminToken);
        self::assertSame(200, $status, 'test endpoint error: '.json_encode($body));
        self::assertSame('sent', $body['status']);
    }
}
