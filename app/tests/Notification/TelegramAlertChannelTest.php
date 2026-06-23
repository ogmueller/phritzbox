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
use App\Notification\TelegramAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TelegramAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())->setName('TG')->setType('telegram')
            ->setTarget('12345')->setSecret('bottoken');
    }

    public function testSupports(): void
    {
        $c = new TelegramAlertChannel(new MockHttpClient());
        self::assertTrue($c->supports('telegram'));
        self::assertFalse($c->supports('slack'));
    }

    public function testPostsChatIdAndText(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        (new TelegramAlertChannel($client))->send($this->channel(), 'Subject', 'Body', []);

        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('api.telegram.org/botbottoken/sendMessage', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        self::assertSame('12345', $payload['chat_id']);
        self::assertStringContainsString('Subject', $payload['text']);
        self::assertStringContainsString('Body', $payload['text']);
    }

    public function testThrowsOnError(): void
    {
        $client = new MockHttpClient(new MockResponse('{"ok":false}', ['http_code' => 401]));
        $this->expectException(\RuntimeException::class);
        (new TelegramAlertChannel($client))->send($this->channel(), 'S', 'B', []);
    }
}
