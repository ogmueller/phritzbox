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

use App\Entity\SmartDeviceData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SmartDeviceData|null find($id, $lockMode = null, $lockVersion = null)
 * @method SmartDeviceData|null findOneBy(array $criteria, array $orderBy = null)
 * @method SmartDeviceData[]    findAll()
 * @method SmartDeviceData[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SmartDeviceDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmartDeviceData::class);
    }
}
