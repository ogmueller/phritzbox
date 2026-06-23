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

final class WebhookAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_WEBHOOK;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $response = $this->httpClient->request('POST', $channel->getTarget(), [
            'json' => array_merge([
                'subject' => $subject,
                'message' => $body,
            ], $context),
            'timeout' => 10,
        ]);

        // Force the request to complete and surface transport/HTTP failures.
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Webhook returned HTTP %d', $response->getStatusCode()));
        }
    }
}
