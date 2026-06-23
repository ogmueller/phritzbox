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

namespace App\Tests\Notification;

use App\Entity\NotificationChannel;
use App\Notification\DiscordAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DiscordAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())->setName('D')->setType('discord')
            ->setTarget('https://discord.com/api/webhooks/1/abc');
    }

    public function testSupports(): void
    {
        self::assertTrue((new DiscordAlertChannel(new MockHttpClient()))->supports('discord'));
    }

    public function testPostsContent(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('', ['http_code' => 204]);
        });

        (new DiscordAlertChannel($client))->send($this->channel(), 'Subject', 'Body', []);

        self::assertSame('https://discord.com/api/webhooks/1/abc', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        self::assertStringContainsString('Subject', $payload['content']);
        self::assertStringContainsString('Body', $payload['content']);
    }

    public function testThrowsOnError(): void
    {
        $client = new MockHttpClient(new MockResponse('bad', ['http_code' => 400]));
        $this->expectException(\RuntimeException::class);
        (new DiscordAlertChannel($client))->send($this->channel(), 'S', 'B', []);
    }
}
