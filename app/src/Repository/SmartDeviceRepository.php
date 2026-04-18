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

namespace App\Repository;

use App\Device;
use App\Entity\SmartDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmartDevice>
 */
class SmartDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmartDevice::class);
    }

    public function upsertFromDevice(Device $device, \DateTimeImmutable $now): SmartDevice
    {
        $ain = $device->getIdentifier();
        $entity = $this->find($ain);

        if ($entity === null) {
            $entity = new SmartDevice($ain);
            $entity->setFirstSeenAt($now);
            $this->getEntityManager()->persist($entity);
        }

        $entity->setName($device->getName());
        $entity->setManufacturer($device->getManufacturer());
        $entity->setProductName($device->getProductName());
        $entity->setFirmwareVersion($device->getFirmwareVersion());
        $entity->setFunctionBitMask($device->getFunctionBitMask());
        $entity->setLastSeenAt($now);

        return $entity;
    }

    /**
     * @return SmartDevice[] indexed by AIN
     */
    public function findAllIndexedByAin(): array
    {
        $devices = $this->findAll();
        $indexed = [];
        foreach ($devices as $device) {
            $indexed[$device->getAin()] = $device;
        }

        return $indexed;
    }
}
