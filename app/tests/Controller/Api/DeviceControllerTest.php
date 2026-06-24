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

use App\Entity\SmartDevice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DeviceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminToken;
    private string $userToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $jwt = $container->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setUsername('deviceadmin')
            ->setEmail('deviceadmin@test.com')
            ->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pass'));
        $this->em->persist($admin);

        $user = new User();
        $user->setUsername('deviceuser')
            ->setEmail('deviceuser@test.com')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $device = new SmartDevice('test-dev-001');
        $device->setName('Server Rack');
        $this->em->persist($device);

        $this->em->flush();

        $this->adminToken = $jwt->create($admin);
        $this->userToken = $jwt->create($user);
    }

    private function putProtection(string $ain, ?string $token, mixed $body): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request('PUT', '/api/devices/'.$ain.'/protection', server: $server, content: json_encode($body));
    }

    public function testProtectionRequiresAuth(): void
    {
        $this->putProtection('test-dev-001', null, ['confirmOn' => true, 'confirmOff' => true]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminCannotSetProtection(): void
    {
        $this->putProtection('test-dev-001', $this->userToken, ['confirmOn' => true, 'confirmOff' => true]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSetsConfirmFlagsIndependently(): void
    {
        // Confirm only on turn-off, not on turn-on.
        $this->putProtection('test-dev-001', $this->adminToken, ['confirmOn' => false, 'confirmOff' => true]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('test-dev-001', $data['ain']);
        self::assertFalse($data['confirmOn']);
        self::assertTrue($data['confirmOff']);

        $this->em->clear();
        $device = $this->em->getRepository(SmartDevice::class)->find('test-dev-001');
        self::assertFalse($device->isConfirmOn());
        self::assertTrue($device->isConfirmOff());
    }

    public function testAdminCanClearConfirmFlags(): void
    {
        $this->putProtection('test-dev-001', $this->adminToken, ['confirmOn' => true, 'confirmOff' => true]);
        $this->putProtection('test-dev-001', $this->adminToken, ['confirmOn' => false, 'confirmOff' => false]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($data['confirmOn']);
        self::assertFalse($data['confirmOff']);
    }

    public function testProtectionRejectsMissingFlag(): void
    {
        // Only one flag supplied — both are required.
        $this->putProtection('test-dev-001', $this->adminToken, ['confirmOn' => true]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testProtectionRejectsNonBooleanBody(): void
    {
        $this->putProtection('test-dev-001', $this->adminToken, ['confirmOn' => 'yes', 'confirmOff' => false]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testProtectionReturns404ForUnknownDevice(): void
    {
        $this->putProtection('does-not-exist', $this->adminToken, ['confirmOn' => true, 'confirmOff' => true]);
        self::assertResponseStatusCodeSame(404);
    }
}
