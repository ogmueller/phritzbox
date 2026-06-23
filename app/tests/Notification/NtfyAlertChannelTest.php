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
use App\Notification\NtfyAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NtfyAlertChannelTest extends TestCase
{
    private function channel(?string $secret = null): NotificationChannel
    {
        return (new NotificationChannel())->setName('N')->setType('ntfy')
            ->setTarget('https://ntfy.sh/mytopic')->setSecret($secret);
    }

    public function testSupports(): void
    {
        $c = new NtfyAlertChannel(new MockHttpClient());
        self::assertTrue($c->supports('ntfy'));
        self::assertFalse($c->supports('email'));
    }

    public function testPostsBodyWithTitleHeader(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'body' => $options['body'] ?? null, 'headers' => implode("\n", $options['headers'] ?? [])];

            return new MockResponse('', ['http_code' => 200]);
        });

        (new NtfyAlertChannel($client))->send($this->channel('tok'), 'My Title', 'Message body', []);

        self::assertSame('https://ntfy.sh/mytopic', $captured['url']);
        self::assertSame('Message body', (string) $captured['body']);
        self::assertStringContainsString('Title: My Title', $captured['headers']);
        self::assertStringContainsString('Authorization: Bearer tok', $captured['headers']);
    }

    public function testNoAuthHeaderWithoutSecret(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = implode("\n", $options['headers'] ?? []);

            return new MockResponse('', ['http_code' => 200]);
        });

        (new NtfyAlertChannel($client))->send($this->channel(null), 'T', 'B', []);
        self::assertStringNotContainsString('Authorization:', $captured);
    }
}
