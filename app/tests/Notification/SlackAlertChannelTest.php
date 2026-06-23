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
use App\Notification\SlackAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SlackAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())->setName('S')->setType('slack')
            ->setTarget('https://hooks.slack.com/services/X/Y/Z');
    }

    public function testSupports(): void
    {
        self::assertTrue((new SlackAlertChannel(new MockHttpClient()))->supports('slack'));
    }

    public function testPostsText(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        (new SlackAlertChannel($client))->send($this->channel(), 'Subject', 'Body', []);

        self::assertSame('https://hooks.slack.com/services/X/Y/Z', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        self::assertStringContainsString('Subject', $payload['text']);
        self::assertStringContainsString('Body', $payload['text']);
    }

    public function testThrowsOnError(): void
    {
        $client = new MockHttpClient(new MockResponse('invalid_payload', ['http_code' => 400]));
        $this->expectException(\RuntimeException::class);
        (new SlackAlertChannel($client))->send($this->channel(), 'S', 'B', []);
    }
}
