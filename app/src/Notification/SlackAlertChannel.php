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
 * Delivers alerts to a Slack-compatible incoming webhook (`target`).
 * Works with Slack, Mattermost and Rocket.Chat, which all accept {"text": …}.
 */
final class SlackAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_SLACK;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $response = $this->httpClient->request('POST', $channel->getTarget(), [
            'json' => ['text' => $subject."\n\n".$body],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Slack webhook returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
