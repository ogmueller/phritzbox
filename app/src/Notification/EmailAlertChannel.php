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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class EmailAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'APP_ALERT_FROM')]
        private readonly string $from,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === NotificationChannel::TYPE_EMAIL;
    }

    public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
    {
        $email = (new Email())
            ->from($this->from)
            ->to($channel->getTarget())
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}
