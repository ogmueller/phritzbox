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
use App\Notification\GotifyAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GotifyAlertChannelTest extends TestCase
{
    private function channel(): NotificationChannel
    {
        return (new NotificationChannel())->setName('G')->setType('gotify')
            ->setTarget('https://gotify.example.com/')->setSecret('apptoken');
    }

    public function testSupports(): void
    {
        self::assertTrue((new GotifyAlertChannel(new MockHttpClient()))->supports('gotify'));
    }

    public function testPostsMessageWithToken(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'body' => $options['body'] ?? null, 'headers' => implode("\n", $options['headers'] ?? [])];

            return new MockResponse('{"id":1}', ['http_code' => 200]);
        });

        (new GotifyAlertChannel($client))->send($this->channel(), 'Subject', 'Body', []);

        self::assertSame('https://gotify.example.com/message', $captured['url']);
        self::assertStringContainsString('X-Gotify-Key: apptoken', $captured['headers']);
        $payload = json_decode((string) $captured['body'], true);
        self::assertSame('Subject', $payload['title']);
        self::assertSame('Body', $payload['message']);
    }

    public function testThrowsOnError(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 403]));
        $this->expectException(\RuntimeException::class);
        (new GotifyAlertChannel($client))->send($this->channel(), 'S', 'B', []);
    }
}
