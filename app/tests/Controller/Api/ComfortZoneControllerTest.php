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

class ComfortZoneControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $adminToken;
    private string $userToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $jwt = $container->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setUsername('comfortadmin')->setEmail('comfortadmin@test.com')->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pass'));
        $em->persist($admin);

        $user = new User();
        $user->setUsername('comfortuser')->setEmail('comfortuser@test.com')->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pass'));
        $em->persist($user);

        $em->flush();

        $this->adminToken = $jwt->create($admin);
        $this->userToken = $jwt->create($user);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, ?string $token, ?array $body = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, '/api/settings/comfort-zone', server: $server, content: $body === null ? null : (string) json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testRequiresAuth(): void
    {
        $this->request('GET', null);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnyUserReadsTheDefaults(): void
    {
        $data = $this->request('GET', $this->userToken);

        self::assertResponseIsSuccessful();
        self::assertEquals(['coldBelow' => 16.0, 'warmFrom' => 20.0], $data);
    }

    public function testAPlainUserCannotWrite(): void
    {
        $this->request('PUT', $this->userToken, ['coldBelow' => 10, 'warmFrom' => 22]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminRoundTripAcceptsADecimalComma(): void
    {
        $saved = $this->request('PUT', $this->adminToken, ['coldBelow' => '17,5', 'warmFrom' => 22]);

        self::assertResponseIsSuccessful();
        self::assertEquals(['coldBelow' => 17.5, 'warmFrom' => 22.0], $saved);
        self::assertEquals($saved, $this->request('GET', $this->userToken));
    }

    public function testAZeroWidthZoneIsAllowed(): void
    {
        $this->request('PUT', $this->adminToken, ['coldBelow' => 18, 'warmFrom' => 18]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing warmFrom' => [['coldBelow' => 16], 'warmFrom'];
        yield 'blank coldBelow' => [['coldBelow' => '', 'warmFrom' => 20], 'coldBelow'];
        yield 'not a number' => [['coldBelow' => 'cold', 'warmFrom' => 20], 'coldBelow'];
        yield 'below the sensor range' => [['coldBelow' => -40, 'warmFrom' => 20], 'between'];
        yield 'above the sensor range' => [['coldBelow' => 16, 'warmFrom' => 45], 'between'];
        yield 'cold above warm' => [['coldBelow' => 22, 'warmFrom' => 18], 'must not be above'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejectedAndChangeNothing(array $body, string $message): void
    {
        $error = $this->request('PUT', $this->adminToken, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString($message, (string) $error['error']);
        self::assertEquals(['coldBelow' => 16.0, 'warmFrom' => 20.0], $this->request('GET', $this->userToken));
    }
}
