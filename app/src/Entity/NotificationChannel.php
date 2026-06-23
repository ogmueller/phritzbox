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

namespace App\Entity;

use App\Repository\NotificationChannelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reusable notification destination (email address or webhook URL) that
 * alert rules can target.
 */
#[ORM\Entity(repositoryClass: NotificationChannelRepository::class)]
class NotificationChannel
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_PUSHOVER = 'pushover';
    public const TYPE_TELEGRAM = 'telegram';
    public const TYPE_NTFY = 'ntfy';
    public const TYPE_DISCORD = 'discord';
    public const TYPE_GOTIFY = 'gotify';
    public const TYPE_SLACK = 'slack';

    public const TYPES = [
        self::TYPE_EMAIL,
        self::TYPE_WEBHOOK,
        self::TYPE_PUSHOVER,
        self::TYPE_TELEGRAM,
        self::TYPE_NTFY,
        self::TYPE_DISCORD,
        self::TYPE_GOTIFY,
        self::TYPE_SLACK,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type;

    /** Primary destination: email address, webhook URL, or Pushover user/group key. */
    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $target;

    /** Optional credential, e.g. the Pushover application API token. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $secret = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
