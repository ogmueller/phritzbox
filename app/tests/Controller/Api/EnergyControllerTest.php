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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EnergyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->conn = $container->get('doctrine.dbal.default_connection');

        $user = new User();
        $user->setUsername('energyuser')->setEmail('energy@test.com')->setRoles(['ROLE_USER']);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'pass'));
        $em->persist($user);
        $em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function device(string $ain, string $name = 'Outlet'): void
    {
        $this->conn->insert('smart_device', [
            'ain' => $ain, 'name' => $name, 'manufacturer' => '', 'product_name' => '',
            'firmware_version' => '', 'function_bit_mask' => 0,
            'first_seen_at' => '2020-01-01 00:00:00', 'last_seen_at' => '2020-01-01 00:00:00',
        ]);
    }

    private function energy(string $ain, string $day, float $wh): void
    {
        $this->conn->insert('smart_device_data', [
            'sid' => $ain, 'type' => 'energy', 'time' => $day.' 00:00:00', 'value' => $wh,
        ]);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testCostRequiresAuth(): void
    {
        $this->client->request('GET', '/api/energy/cost');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSummaryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/energy/summary');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMalformedBoundIsRejected(): void
    {
        // Same contract as the stats endpoint.
        $this->get('/api/energy/cost?from=not-a-date');
        self::assertResponseStatusCodeSame(400);
    }

    public function testCostReportsEnergyAndCoverage(): void
    {
        $this->device('en-1', 'Fridge');
        $this->energy('en-1', '2026-06-01', 1000.0);
        $this->energy('en-1', '2026-06-02', 500.0);

        $data = $this->get('/api/energy/cost?from=2026-06-01&to=2026-06-02');

        self::assertResponseIsSuccessful();
        // JSON has no int/float distinction, so whole values decode as int.
        self::assertEquals(1500.0, $data['household']['energyWh']);
        $device = $data['devices'][0];
        self::assertSame('en-1', $device['ain']);
        self::assertSame('Fridge', $device['name']);
        self::assertSame(2, $device['coverage']['daysWithData']);
        self::assertSame(0, $device['coverage']['gapDays']);
    }

    public function testCostIsNullWithoutATariff(): void
    {
        $this->device('en-2');
        $this->energy('en-2', '2026-06-01', 1000.0);

        $data = $this->get('/api/energy/cost?from=2026-06-01&to=2026-06-01');

        self::assertFalse($data['configured']);
        self::assertNull($data['devices'][0]['cost']);
        self::assertNull($data['household']['cost']);
    }

    public function testCostIsCalculatedOnceATariffExists(): void
    {
        static::getContainer()->get(TariffSettings::class)->save(0.40, 0.0, 'EUR');
        $this->device('en-3');
        $this->energy('en-3', '2026-06-01', 2000.0);

        $data = $this->get('/api/energy/cost?from=2026-06-01&to=2026-06-01');

        self::assertTrue($data['configured']);
        self::assertSame('EUR', $data['currency']);
        // 2 kWh x 0.40
        self::assertEquals(0.80, $data['devices'][0]['cost']);
    }

    /**
     * The two endpoints must agree about what "this window" means. A mid-day
     * `from` is widened to midnight for energy in both, so the cost footer on
     * Reports cannot contradict the bars directly above it.
     */
    public function testCostTotalMatchesTheStatsEndpointForTheSameWindow(): void
    {
        $this->device('en-agree');
        $this->energy('en-agree', '2026-06-01', 500.0);
        $this->energy('en-agree', '2026-06-02', 700.0);

        $window = 'from=2026-06-01T14:00:00%2B00:00&to=2026-06-02T23:59:59%2B00:00';

        $stats = $this->get('/api/stats/en-agree?type=energy&'.$window);
        $chartTotal = array_sum(array_column($stats['data'], 'value'));

        $cost = $this->get('/api/energy/cost?'.$window);

        self::assertEquals(1200.0, $chartTotal, 'the chart widens to midnight and shows both days');
        self::assertEquals($chartTotal, $cost['devices'][0]['energyWh']);
    }

    public function testSummaryShape(): void
    {
        $data = $this->get('/api/energy/summary');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('today', $data);
        self::assertArrayHasKey('monthToDate', $data);
        self::assertArrayHasKey('topConsumer', $data);
        self::assertArrayHasKey('estimated', $data['today']);
        self::assertArrayHasKey('currency', $data);
    }

    public function testSummaryTopConsumerIsTheLargestOfTheMonth(): void
    {
        $now = new \DateTimeImmutable();
        $day = $now->modify('-1 day');
        if ($day->format('Y-m') !== $now->format('Y-m')) {
            self::markTestSkipped('run on the first of a month');
        }

        $this->device('en-top', 'Big');
        $this->device('en-low', 'Small');
        $this->energy('en-top', $day->format('Y-m-d'), 9000.0);
        $this->energy('en-low', $day->format('Y-m-d'), 100.0);

        $data = $this->get('/api/energy/summary');

        self::assertSame('en-top', $data['topConsumer']['ain']);
        self::assertSame('Big', $data['topConsumer']['name']);
    }

    public function testStandbyRequiresAuth(): void
    {
        $this->client->request('GET', '/api/energy/standby/en-1');
        self::assertResponseStatusCodeSame(401);
    }

    public function testStandbyReportsAFloorForADeviceWithAWeekOfReadings(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-http');
        // A week of 10-minute readings, stored in centiwatts.
        $t = $now->modify('-7 days')->modify('+10 minutes');
        while ($t < $now) {
            $this->conn->insert('smart_device_data', [
                'sid' => 'sb-http', 'type' => 'power',
                'time' => $t->format('Y-m-d H:i:s'), 'value' => 700.0,
            ]);
            $t = $t->modify('+10 minutes');
        }

        $data = $this->get('/api/energy/standby/sb-http');

        self::assertResponseIsSuccessful();
        self::assertEquals(7.0, $data['watts']);
        self::assertArrayHasKey('currency', $data);
        self::assertArrayHasKey('samples', $data);
        self::assertSame(7, $data['windowDays']);
        // The idle-while-on pair travels with the floor, so a client never has
        // to make a second request to tell "off" from "on and drawing nothing".
        self::assertArrayHasKey('dutyCyclePercent', $data);
        self::assertArrayHasKey('idleWatts', $data);
    }

    public function testStandbyAnswers204RatherThanAnInventedZero(): void
    {
        // A device with an hour of history has no meaningful weekly floor. 204,
        // not 200 with watts=0 — the caller must be able to tell "we don't know"
        // from "it draws nothing".
        $now = new \DateTimeImmutable();
        $this->device('sb-thin-http');
        foreach (range(1, 5) as $i) {
            $this->conn->insert('smart_device_data', [
                'sid' => 'sb-thin-http', 'type' => 'power',
                'time' => $now->modify(\sprintf('-%d minutes', $i * 10))->format('Y-m-d H:i:s'),
                'value' => 500.0,
            ]);
        }

        $this->client->request('GET', '/api/energy/standby/sb-thin-http', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testStandbyAnswers204ForAnUnknownDevice(): void
    {
        $this->client->request('GET', '/api/energy/standby/nope', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testStandbyAcceptsAnAinContainingASpace(): void
    {
        // Real AINs look like "08761 0372830"; the route must not stop at the space.
        $this->device('08761 0372830');

        $this->client->request('GET', '/api/energy/standby/'.rawurlencode('08761 0372830'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(204, 'no readings, but the route resolved');
    }
}
