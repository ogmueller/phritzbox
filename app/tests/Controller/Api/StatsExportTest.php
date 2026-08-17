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

class StatsExportTest extends WebTestCase
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
        $user->setUsername('exportuser')->setEmail('export@test.com')->setRoles(['ROLE_USER']);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'pass'));
        $em->persist($user);
        $em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function reading(string $sid, string $type, string $time, float $value): void
    {
        $this->conn->insert('smart_device_data', ['sid' => $sid, 'type' => $type, 'time' => $time, 'value' => $value]);
    }

    private function get(string $url): string
    {
        $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testExportRequiresAuth(): void
    {
        $this->client->request('GET', '/api/stats/x/export');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCsvExportHasHeadersAndRows(): void
    {
        $this->reading('exp-1', 'temperature', '2026-06-01 10:00:00', 21.5);
        $this->reading('exp-1', 'temperature', '2026-06-01 10:30:00', 22.0);

        $body = $this->get('/api/stats/exp-1/export?type=temperature&from=2026-06-01&to=2026-06-01');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('time,type,value,unit', $body);
        self::assertStringContainsString('temperature', $body);
        self::assertStringContainsString('°C', $body);
    }

    public function testCsvDelimiterCanBeSwitchedForGermanExcel(): void
    {
        $this->reading('exp-2', 'temperature', '2026-06-01 10:00:00', 21.5);

        $body = $this->get('/api/stats/exp-2/export?type=temperature&from=2026-06-01&to=2026-06-01&delimiter=;');

        self::assertStringContainsString('time;type;value;unit', $body);
    }

    public function testAggregatedExportCarriesMinAndMax(): void
    {
        // A 7-day window is served aggregated, so the extremes are meaningful.
        $this->reading('exp-3', 'power', '2026-06-01 10:00:00', 10.0);
        $this->reading('exp-3', 'power', '2026-06-05 10:00:00', 2000.0);

        $body = $this->get('/api/stats/exp-3/export?type=power&from=2026-06-01&to=2026-06-08');

        self::assertStringContainsString('time,type,value,unit,min,max', $body);
    }

    public function testRawExportOmitsMinAndMax(): void
    {
        $this->reading('exp-4', 'power', '2026-06-01 10:00:00', 10.0);

        $body = $this->get('/api/stats/exp-4/export?type=power&from=2026-06-01&to=2026-06-01');

        self::assertStringContainsString('time,type,value,unit', $body);
        self::assertStringNotContainsString('min,max', $body);
    }

    public function testJsonExportMatchesTheApiPayload(): void
    {
        $this->reading('exp-5', 'temperature', '2026-06-01 10:00:00', 21.5);

        $body = $this->get('/api/stats/exp-5/export?type=temperature&from=2026-06-01&to=2026-06-01&format=json');

        self::assertResponseIsSuccessful();
        $decoded = json_decode($body, true);
        self::assertSame('exp-5', $decoded['ain']);
        self::assertSame('raw', $decoded['resolution']);
        self::assertCount(1, $decoded['data']);
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->client->request('GET', '/api/stats/exp-6/export?format=xlsx', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testEnergyExportCoversTheSameDaysAsTheChart(): void
    {
        // Energy is stamped at midnight, so a mid-day `from` must be widened the
        // same way in both endpoints. Before StatsRange the export clipped the
        // first day while the chart showed it — the download disagreed with what
        // was on screen.
        $this->reading('exp-widen', 'energy', '2026-06-01 00:00:00', 500.0);
        $this->reading('exp-widen', 'energy', '2026-06-02 00:00:00', 700.0);

        $window = 'type=energy&from=2026-06-01T14:00:00%2B00:00&to=2026-06-02T23:59:59%2B00:00';

        $this->client->request('GET', '/api/stats/exp-widen?'.$window, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);
        $chart = json_decode((string) $this->client->getResponse()->getContent(), true);

        $csv = $this->get('/api/stats/exp-widen/export?'.$window);

        self::assertCount(2, $chart['data'], 'the chart widens to midnight and shows both days');
        // Header + one line per day, ignoring the trailing newline.
        self::assertCount(3, array_filter(explode("\n", $csv), static fn (string $l): bool => $l !== ''));
        self::assertStringContainsString('500', $csv, 'the first day must not be clipped from the export');
    }

    public function testExportRouteIsNotSwallowedByTheShowRoute(): void
    {
        // /{ain}/export must not be read as ain="exp-7/export".
        $this->reading('exp-7', 'temperature', '2026-06-01 10:00:00', 21.5);

        $this->get('/api/stats/exp-7/export?type=temperature&from=2026-06-01&to=2026-06-01');

        self::assertStringContainsString(
            'text/csv',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }
}
