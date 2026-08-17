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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pre-aggregated window of readings.
 *
 * Nothing loads this through the ORM — the rollup is written and read with raw
 * DBAL, because it deals in millions of rows and set-based upserts. The mapping
 * exists so `doctrine:schema:validate` still describes the whole schema and the
 * table is not invisible to anyone reading the entity directory.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'smart_device_data_rollup')]
class SmartDeviceDataRollup
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $sid;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $type;

    /** Bucket width in seconds: 900 (quarter-hour) or 86400 (day). */
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $grid;

    /** Start of the window, in the same local clock the raw readings use. */
    #[ORM\Id]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $bucket;

    /** MIN(time) of the source rows — the timestamp the API reports for this point. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $timeMin;

    #[ORM\Column(type: Types::FLOAT)]
    private float $avgValue;

    #[ORM\Column(type: Types::FLOAT)]
    private float $minValue;

    #[ORM\Column(type: Types::FLOAT)]
    private float $maxValue;

    /** With sampleCount, lets a coarser grid be derived exactly from this one. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $sumValue;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sampleCount;

    public function getSid(): string
    {
        return $this->sid;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getGrid(): int
    {
        return $this->grid;
    }

    public function getBucket(): \DateTimeImmutable
    {
        return $this->bucket;
    }

    public function getTimeMin(): \DateTimeImmutable
    {
        return $this->timeMin;
    }

    public function getAvgValue(): float
    {
        return $this->avgValue;
    }

    public function getMinValue(): float
    {
        return $this->minValue;
    }

    public function getMaxValue(): float
    {
        return $this->maxValue;
    }

    public function getSumValue(): float
    {
        return $this->sumValue;
    }

    public function getSampleCount(): int
    {
        return $this->sampleCount;
    }
}
