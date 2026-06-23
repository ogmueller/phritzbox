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

use App\Repository\AlertRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlertRuleRepository::class)]
class AlertRule
{
    public const MODE_THRESHOLD = 'threshold';
    public const MODE_COMPARISON = 'comparison';

    public const STATE_OK = 'ok';
    public const STATE_TRIGGERED = 'triggered';

    public const OPERATORS = ['gt', 'lt', 'gte', 'lte'];
    public const MODES = [self::MODE_THRESHOLD, self::MODE_COMPARISON];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $mode = self::MODE_THRESHOLD;

    /** Subject device AIN. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $sid;

    /** Subject metric type (temperature|power|voltage|energy). */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type;

    /** Comparison operator: gt|lt|gte|lte. */
    #[ORM\Column(type: Types::STRING, length: 4)]
    private string $operator;

    /** Threshold in display units (threshold mode). */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $threshold = null;

    /** Device B AIN (comparison mode). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $compareSid = null;

    /** Device B metric type (comparison mode). */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $compareType = null;

    /** Offset added to device B's value, in display units (comparison mode). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $compareOffset = 0.0;

    /** 0 = evaluate the latest reading; >0 = condition must hold for the last N minutes (threshold mode). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $durationMinutes = 0;

    /** @var Collection<int, NotificationChannel> */
    #[ORM\ManyToMany(targetEntity: NotificationChannel::class)]
    #[ORM\JoinTable(name: 'alert_rule_channel')]
    private Collection $channels;

    /** 0 = alert once until the condition clears; >0 = re-remind every N minutes while still met. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $cooldownMinutes = 0;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $lastState = self::STATE_OK;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->channels = new ArrayCollection();
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function setSid(string $sid): self
    {
        $this->sid = $sid;

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

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function setOperator(string $operator): self
    {
        $this->operator = $operator;

        return $this;
    }

    public function getThreshold(): ?float
    {
        return $this->threshold;
    }

    public function setThreshold(?float $threshold): self
    {
        $this->threshold = $threshold;

        return $this;
    }

    public function getCompareSid(): ?string
    {
        return $this->compareSid;
    }

    public function setCompareSid(?string $compareSid): self
    {
        $this->compareSid = $compareSid;

        return $this;
    }

    public function getCompareType(): ?string
    {
        return $this->compareType;
    }

    public function setCompareType(?string $compareType): self
    {
        $this->compareType = $compareType;

        return $this;
    }

    public function getCompareOffset(): float
    {
        return $this->compareOffset;
    }

    public function setCompareOffset(float $compareOffset): self
    {
        $this->compareOffset = $compareOffset;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): self
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(NotificationChannel $channel): self
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
        }

        return $this;
    }

    public function removeChannel(NotificationChannel $channel): self
    {
        $this->channels->removeElement($channel);

        return $this;
    }

    public function clearChannels(): self
    {
        $this->channels->clear();

        return $this;
    }

    public function getCooldownMinutes(): int
    {
        return $this->cooldownMinutes;
    }

    public function setCooldownMinutes(int $cooldownMinutes): self
    {
        $this->cooldownMinutes = $cooldownMinutes;

        return $this;
    }

    public function getLastState(): string
    {
        return $this->lastState;
    }

    public function setLastState(string $lastState): self
    {
        $this->lastState = $lastState;

        return $this;
    }

    public function getLastTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->lastTriggeredAt;
    }

    public function setLastTriggeredAt(?\DateTimeImmutable $lastTriggeredAt): self
    {
        $this->lastTriggeredAt = $lastTriggeredAt;

        return $this;
    }

    public function getLastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    public function setLastNotifiedAt(?\DateTimeImmutable $lastNotifiedAt): self
    {
        $this->lastNotifiedAt = $lastNotifiedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
