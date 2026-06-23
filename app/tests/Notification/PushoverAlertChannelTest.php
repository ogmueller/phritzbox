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
use App\Notification\PushoverAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushoverAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())
            ->setName('Phone')
            ->setType('pushover')
            ->setTarget('user-key-123')
            ->setSecret('app-token-456');
    }

    public function testSupports(): void
    {
        $channel = new PushoverAlertChannel(new MockHttpClient());
        self::assertTrue($channel->supports('pushover'));
        self::assertFalse($channel->supports('email'));
    }

    public function testPostsTokenUserAndMessage(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('{"status":1}', ['http_code' => 200]);
        });

        $channel = new PushoverAlertChannel($client);
        $channel->send($this->channel(), 'My Title', 'My message body', []);

        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('api.pushover.net/1/messages.json', $captured['url']);
        // Symfony serializes an array body to a urlencoded string.
        $body = \is_array($captured['body']) ? http_build_query($captured['body']) : (string) $captured['body'];
        self::assertStringContainsString('token=app-token-456', $body);
        self::assertStringContainsString('user=user-key-123', $body);
        self::assertStringContainsString('My+message+body', $body);
    }

    public function testThrowsOnErrorStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('{"errors":["bad token"]}', ['http_code' => 400]));
        $channel = new PushoverAlertChannel($client);

        $this->expectException(\RuntimeException::class);
        $channel->send($this->channel(), 'T', 'B', []);
    }
}
