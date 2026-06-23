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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.alert_channel')]
interface AlertChannelInterface
{
    /**
     * @param string $type one of NotificationChannel::TYPES
     */
    public function supports(string $type): bool;

    /**
     * @param array<string, mixed> $context structured payload (used by the webhook channel)
     */
    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void;
}
