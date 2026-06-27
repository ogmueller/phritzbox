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

use App\Repository\AlertEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit record of an alert rule firing or resolving, including the
 * per-channel notification outcome. Rule data is denormalised so the history
 * survives deletion of the rule itself.
 */
#[ORM\Entity(repositoryClass: AlertEventRepository::class)]
#[ORM\Index(columns: ['created_at'], name: 'idx_alert_event_created')]
class AlertEvent
{
    public const STATE_TRIGGERED = 'triggered';
    public const STATE_RESOLVED = 'resolved';
    public const STATE_REARMED = 'rearmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Rule id at the time of the event; nullable so the log outlives the rule. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ruleId = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $ruleName;

    /** triggered | resolved */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $state;

    /** Subject metric type (temperature|power|voltage|energy). */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type;

    /** Subject value in display units at evaluation time. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $valueDisplay = null;

    /** Compared device value in display units (comparison rules only). */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $compareDisplay = null;

    /** @var array<int, array{channel: string, type: string, ok: bool, error: string|null}> */
    #[ORM\Column(type: Types::JSON)]
    private array $deliveries = [];

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

    public function getRuleId(): ?int
    {
        return $this->ruleId;
    }

    public function setRuleId(?int $ruleId): self
    {
        $this->ruleId = $ruleId;

        return $this;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function setRuleName(string $ruleName): self
    {
        $this->ruleName = $ruleName;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

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

    public function getValueDisplay(): ?float
    {
        return $this->valueDisplay;
    }

    public function setValueDisplay(?float $valueDisplay): self
    {
        $this->valueDisplay = $valueDisplay;

        return $this;
    }

    public function getCompareDisplay(): ?float
    {
        return $this->compareDisplay;
    }

    public function setCompareDisplay(?float $compareDisplay): self
    {
        $this->compareDisplay = $compareDisplay;

        return $this;
    }

    /**
     * @return array<int, array{channel: string, type: string, ok: bool, error: string|null}>
     */
    public function getDeliveries(): array
    {
        return $this->deliveries;
    }

    /**
     * @param array<int, array{channel: string, type: string, ok: bool, error: string|null}> $deliveries
     */
    public function setDeliveries(array $deliveries): self
    {
        $this->deliveries = $deliveries;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
