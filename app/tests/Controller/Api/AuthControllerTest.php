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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setUsername('authuser')
            ->setEmail('auth@test.com')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'secret'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function login(): array
    {
        $this->client->jsonRequest('POST', '/api/auth/login', [
            'username' => 'authuser',
            'password' => 'secret',
        ]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testLoginReturnsTokenAndRefreshToken(): void
    {
        $data = $this->login();

        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('refresh_token', $data);
        self::assertNotEmpty($data['refresh_token']);
        self::assertArrayHasKey('refresh_token_expiration', $data);
    }

    public function testRefreshIssuesNewTokenAndRotates(): void
    {
        $login = $this->login();
        $original = $login['refresh_token'];

        $this->client->jsonRequest('POST', '/api/auth/refresh', ['refresh_token' => $original]);
        self::assertResponseIsSuccessful();

        $refreshed = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $refreshed);
        self::assertNotEmpty($refreshed['token']);
        // Rotation: a brand-new refresh token is handed out...
        self::assertNotSame($original, $refreshed['refresh_token']);

        // ...and the original is now invalid (single-use).
        $this->client->jsonRequest('POST', '/api/auth/refresh', ['refresh_token' => $original]);
        self::assertResponseStatusCodeSame(401);

        // The new access token actually authenticates against a protected route.
        $this->client->request('GET', '/api/stats/nonexistent?type=temperature', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$refreshed['token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testRefreshWithUnknownTokenIsUnauthorized(): void
    {
        $this->client->jsonRequest('POST', '/api/auth/refresh', ['refresh_token' => 'does-not-exist']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshWithoutTokenIsBadRequest(): void
    {
        $this->client->jsonRequest('POST', '/api/auth/refresh', []);
        self::assertResponseStatusCodeSame(400);
    }

    public function testLogoutInvalidatesRefreshToken(): void
    {
        $login = $this->login();
        $refreshToken = $login['refresh_token'];

        $this->client->jsonRequest('POST', '/api/auth/logout', ['refresh_token' => $refreshToken]);
        self::assertResponseStatusCodeSame(204);

        // Token no longer works after logout.
        $this->client->jsonRequest('POST', '/api/auth/refresh', ['refresh_token' => $refreshToken]);
        self::assertResponseStatusCodeSame(401);
    }
}
