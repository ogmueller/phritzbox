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

namespace App\Service;

use App\Device;
use App\Entity\SmartDevice;
use App\Repository\SmartDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;

class SmartDeviceService
{
    public function __construct(
        private readonly SmartDeviceRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Upsert device metadata from live Fritz!Box API responses.
     *
     * @param Device[] $devices
     */
    public function syncDevices(array $devices): void
    {
        $now = new \DateTimeImmutable();
        foreach ($devices as $device) {
            $this->repository->upsertFromDevice($device, $now);
        }
        $this->em->flush();
    }

    /**
     * @return SmartDevice[] indexed by AIN
     */
    public function getAllCached(): array
    {
        return $this->repository->findAllIndexedByAin();
    }

    public function findByAin(string $ain): ?SmartDevice
    {
        return $this->repository->find($ain);
    }

    /**
     * Persist the per-direction "confirm before switching" flags for a device.
     *
     * Returns the updated entity, or null if no metadata row exists for the AIN.
     */
    public function setConfirmFlags(string $ain, bool $confirmOn, bool $confirmOff): ?SmartDevice
    {
        $device = $this->repository->find($ain);
        if ($device === null) {
            return null;
        }

        $device->setConfirmOn($confirmOn);
        $device->setConfirmOff($confirmOff);
        $this->em->flush();

        return $device;
    }
}
