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
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Routes a notification to the transport matching the channel's type.
 */
final class AlertNotifier
{
    /**
     * @param iterable<AlertChannelInterface> $transports
     */
    public function __construct(
        #[AutowireIterator('app.alert_channel')]
        private readonly iterable $transports,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notify(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($channel->getType())) {
                $transport->send($channel, $subject, $body, $context);

                return;
            }
        }

        throw new \RuntimeException(\sprintf('No transport supports channel type "%s"', $channel->getType()));
    }
}
