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

class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminToken;
    private User $adminUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Create admin user for tests
        $this->adminUser = new User();
        $this->adminUser->setUsername('testadmin')
            ->setEmail('testadmin@localhost')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $this->adminUser->setPassword($hasher->hashPassword($this->adminUser, 'password'));
        $this->em->persist($this->adminUser);
        $this->em->flush();

        $jwt = $container->get(JWTTokenManagerInterface::class);
        $this->adminToken = $jwt->create($this->adminUser);
    }

    public function testListUsersRequiresAuth(): void
    {
        $this->client->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(401);
    }

    public function testListUsersAsAdmin(): void
    {
        $this->client->request('GET', '/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertNotEmpty($data);
    }

    public function testCreateUser(): void
    {
        $this->client->request('POST', '/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => 'newuser',
            'email' => 'new@test.com',
            'password' => 'secret123',
            'roles' => ['ROLE_USER'],
        ]));

        self::assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('newuser', $data['username']);
        self::assertSame('new@test.com', $data['email']);
    }

    public function testCreateUserMissingFields(): void
    {
        $this->client->request('POST', '/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['username' => 'incomplete']));

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateUserInvalidRoles(): void
    {
        $this->client->request('POST', '/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => 'hacker',
            'email' => 'hacker@test.com',
            'password' => 'pass',
            'roles' => ['ROLE_SUPER_ADMIN'],
        ]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateUser(): void
    {
        $user = new User();
        $user->setUsername('editme')
            ->setEmail('edit@test.com')
            ->setRoles(['ROLE_USER']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('PUT', '/api/users/'.$user->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['email' => 'updated@test.com']));

        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('updated@test.com', $data['email']);
    }

    public function testUpdateUserInvalidRoles(): void
    {
        $user = new User();
        $user->setUsername('editrole')
            ->setEmail('editrole@test.com')
            ->setRoles(['ROLE_USER']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('PUT', '/api/users/'.$user->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['roles' => ['ROLE_ROOT']]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteUser(): void
    {
        $user = new User();
        $user->setUsername('deleteme')
            ->setEmail('delete@test.com')
            ->setRoles(['ROLE_USER']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('DELETE', '/api/users/'.$user->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteSelfForbidden(): void
    {
        $this->client->request('DELETE', '/api/users/'.$this->adminUser->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteNonExistent(): void
    {
        $this->client->request('DELETE', '/api/users/99999', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testChangePassword(): void
    {
        $this->client->request('PUT', '/api/users/me/password', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'currentPassword' => 'password',
            'newPassword' => 'newpassword123',
        ]));

        self::assertResponseIsSuccessful();
    }

    public function testChangePasswordWrongCurrent(): void
    {
        $this->client->request('PUT', '/api/users/me/password', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'currentPassword' => 'wrong',
            'newPassword' => 'newpass',
        ]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testRegularUserCannotAccessUsers(): void
    {
        $user = new User();
        $user->setUsername('regular')
            ->setEmail('regular@test.com')
            ->setRoles(['ROLE_USER']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);
        $userToken = $jwt->create($user);

        $this->client->request('GET', '/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$userToken,
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
