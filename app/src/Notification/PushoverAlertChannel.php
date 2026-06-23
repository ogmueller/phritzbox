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

namespace App\Notification;

use App\Entity\NotificationChannel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers alerts via the Pushover messages API.
 *
 * The channel's `target` holds the Pushover user/group key and `secret` holds
 * the application API token.
 */
final class PushoverAlertChannel implements AlertChannelInterface
{
    private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_PUSHOVER;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'body' => [
                'token' => (string) $channel->getSecret(),
                'user' => $channel->getTarget(),
                'title' => mb_substr($subject, 0, 250),
                'message' => mb_substr($body, 0, 1024),
            ],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Pushover returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
