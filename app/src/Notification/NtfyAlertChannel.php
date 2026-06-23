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
 * Delivers alerts via ntfy (ntfy.sh or self-hosted).
 *
 * The channel's `target` is the full topic URL; the optional `secret` is an
 * access token sent as a bearer credential.
 */
final class NtfyAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_NTFY;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $headers = ['Title' => $subject];
        if (!empty($channel->getSecret())) {
            $headers['Authorization'] = 'Bearer '.$channel->getSecret();
        }

        $response = $this->httpClient->request('POST', $channel->getTarget(), [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('ntfy returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
