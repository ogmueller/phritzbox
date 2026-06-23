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
 * Delivers alerts via a self-hosted Gotify server.
 *
 * The channel's `target` is the server base URL and `secret` is the
 * application token.
 */
final class GotifyAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_GOTIFY;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $response = $this->httpClient->request(
            'POST',
            mb_rtrim($channel->getTarget(), '/').'/message',
            [
                'headers' => ['X-Gotify-Key' => (string) $channel->getSecret()],
                'json' => ['title' => $subject, 'message' => $body, 'priority' => 5],
                'timeout' => 10,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Gotify returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
