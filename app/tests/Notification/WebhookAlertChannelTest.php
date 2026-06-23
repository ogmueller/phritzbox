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
use App\Notification\WebhookAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebhookAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())
            ->setName('Hook')
            ->setType('webhook')
            ->setTarget('https://example.test/hook');
    }

    public function testSupports(): void
    {
        $channel = new WebhookAlertChannel(new MockHttpClient());
        self::assertTrue($channel->supports('webhook'));
        self::assertFalse($channel->supports('email'));
    }

    public function testPostsJsonPayload(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('', ['http_code' => 200]);
        });

        $channel = new WebhookAlertChannel($client);
        $channel->send($this->channel(), 'Subject', 'Body text', ['state' => 'triggered', 'value' => 25.0]);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://example.test/hook', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        self::assertSame('Subject', $payload['subject']);
        self::assertSame('Body text', $payload['message']);
        self::assertSame('triggered', $payload['state']);
        self::assertSame(25.0, $payload['value']);
    }

    public function testThrowsOnErrorStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        $channel = new WebhookAlertChannel($client);

        $this->expectException(\RuntimeException::class);
        $channel->send($this->channel(), 'Subject', 'Body', []);
    }
}
