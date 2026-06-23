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
 * Delivers alerts via the Telegram Bot API.
 *
 * The channel's `target` holds the chat ID and `secret` holds the bot token.
 */
final class TelegramAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_TELEGRAM;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $response = $this->httpClient->request(
            'POST',
            'https://api.telegram.org/bot'.$channel->getSecret().'/sendMessage',
            [
                'json' => [
                    'chat_id' => $channel->getTarget(),
                    'text' => $subject."\n\n".$body,
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 10,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Telegram returned HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
