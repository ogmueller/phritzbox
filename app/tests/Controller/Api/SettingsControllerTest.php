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
use App\Service\TariffSettings;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SettingsControllerTest extends WebTestCase
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
        $admin->setUsername('tariffadmin')->setEmail('tariffadmin@test.com')->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pass'));
        $em->persist($admin);

        $user = new User();
        $user->setUsername('tariffuser')->setEmail('tariffuser@test.com')->setRoles(['ROLE_USER']);
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

        $this->client->request($method, '/api/settings/tariff', server: $server, content: $body === null ? null : json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testTariffRequiresAuth(): void
    {
        $this->request('GET', null);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnyUserCanReadTheTariff(): void
    {
        // Costs render for everyone, so everyone needs the price and currency.
        $data = $this->request('GET', $this->userToken);

        self::assertResponseIsSuccessful();
        self::assertFalse($data['configured']);
        self::assertNull($data['pricePerKwh']);
        self::assertSame('EUR', $data['currency']);
    }

    public function testAPlainUserCannotWriteTheTariff(): void
    {
        $this->request('PUT', $this->userToken, ['pricePerKwh' => 0.32]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminRoundTrip(): void
    {
        $saved = $this->request('PUT', $this->adminToken, [
            'pricePerKwh' => 0.3421,
            'standingChargeMonth' => 11.99,
            'currency' => 'CHF',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($saved['configured']);
        self::assertSame(0.3421, $saved['pricePerKwh']);
        self::assertSame(11.99, $saved['standingChargeMonth']);
        self::assertSame('CHF', $saved['currency']);

        $read = $this->request('GET', $this->userToken);
        self::assertSame(0.3421, $read['pricePerKwh']);
    }

    public function testABlankPriceClearsTheTariff(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32]);

        $cleared = $this->request('PUT', $this->adminToken, ['pricePerKwh' => '']);

        self::assertResponseIsSuccessful();
        self::assertFalse($cleared['configured']);
        self::assertNull($cleared['pricePerKwh']);
    }

    public function testAnEmptyBodyDoesNotSilentlyDeleteTheTariff(): void
    {
        // The failure this guards: a caller that loses the payload — a request
        // whose body was dropped, a form that submitted nothing — would otherwise
        // wipe a configured price and be told it succeeded.
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32]);

        $error = $this->request('PUT', $this->adminToken, []);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('pricePerKwh', (string) $error['error']);

        $read = $this->request('GET', $this->userToken);
        self::assertSame(0.32, $read['pricePerKwh'], 'the stored tariff survived');
        self::assertTrue($read['configured']);
    }

    public function testAMissingPriceKeyIsRejectedEvenWithOtherFieldsPresent(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32]);

        $this->request('PUT', $this->adminToken, ['standingChargeMonth' => 12, 'currency' => 'EUR']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0.32, $this->request('GET', $this->userToken)['pricePerKwh']);
    }

    public function testAZeroPriceIsAcceptedAndCountsAsConfigured(): void
    {
        $saved = $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0]);

        self::assertResponseIsSuccessful();
        self::assertTrue($saved['configured'], 'own-generation households may legitimately pay nothing');
        // JSON has no int/float distinction, so 0.0 decodes as 0. The point is
        // that it is present and not null.
        self::assertNotNull($saved['pricePerKwh']);
        self::assertEquals(0.0, $saved['pricePerKwh']);
    }

    public function testRejectsANegativePrice(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => -1]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsAPriceThatLooksLikeCents(): void
    {
        // 32 per kWh is not a tariff; the message should say so.
        $error = $this->request('PUT', $this->adminToken, ['pricePerKwh' => 32]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('cents', $error['error']);
    }

    public function testRejectsANonNumericPrice(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 'cheap']);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The failure this guards: a German operator follows the field's own hint
     * ("z. B. 0,35 für 35 Cent"), the price is refused as non-numeric, and the
     * tariff stays unset while the dashboard keeps asking for one.
     */
    public function testAcceptsADecimalComma(): void
    {
        $saved = $this->request('PUT', $this->adminToken, [
            'pricePerKwh' => '0,35',
            'standingChargeMonth' => '11,99',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($saved['configured']);
        self::assertSame(0.35, $saved['pricePerKwh']);
        self::assertSame(11.99, $saved['standingChargeMonth']);
    }

    /**
     * A standing charge is the one field big enough to be typed with a thousands
     * separator. Whichever separator comes last is the decimal one.
     */
    public function testAcceptsEitherThousandsConvention(): void
    {
        $german = $this->request('PUT', $this->adminToken, [
            'pricePerKwh' => '0,35',
            'standingChargeMonth' => '1.000,00',
        ]);
        self::assertResponseIsSuccessful();
        // JSON has no int/float distinction, so a round 1000.0 decodes as an int.
        self::assertEquals(1000.0, $german['standingChargeMonth']);

        $english = $this->request('PUT', $this->adminToken, [
            'pricePerKwh' => '0.35',
            'standingChargeMonth' => '1,000.00',
        ]);
        self::assertResponseIsSuccessful();
        // JSON has no int/float distinction, so a round 1000.0 decodes as an int.
        self::assertEquals(1000.0, $english['standingChargeMonth']);
    }

    public function testASurroundingSpaceDoesNotMakeAPriceNonNumeric(): void
    {
        $saved = $this->request('PUT', $this->adminToken, ['pricePerKwh' => ' 0,35 ']);

        self::assertResponseIsSuccessful();
        self::assertSame(0.35, $saved['pricePerKwh']);
    }

    public function testAStringOfSpacesStillClearsTheTariffRatherThanErroring(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32]);

        $cleared = $this->request('PUT', $this->adminToken, ['pricePerKwh' => '   ']);

        self::assertResponseIsSuccessful();
        self::assertFalse($cleared['configured']);
    }

    public function testRejectsANegativeStandingCharge(): void
    {
        $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32, 'standingChargeMonth' => -5]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The allowlist is what keeps Intl.NumberFormat from throwing RangeError in
     * the browser, which would blank the page rather than merely look wrong.
     */
    public function testRejectsAnUnknownCurrencyCode(): void
    {
        $error = $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32, 'currency' => 'XYZ']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('currency must be one of', $error['error']);
    }

    public function testCurrencyIsNormalisedToUppercase(): void
    {
        $saved = $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32, 'currency' => 'gbp']);

        self::assertResponseIsSuccessful();
        self::assertSame('GBP', $saved['currency']);
    }

    public function testEveryAllowedCurrencyIsAccepted(): void
    {
        foreach (TariffSettings::CURRENCIES as $code) {
            $saved = $this->request('PUT', $this->adminToken, ['pricePerKwh' => 0.32, 'currency' => $code]);
            self::assertResponseIsSuccessful($code);
            self::assertSame($code, $saved['currency']);
        }
    }
}
