<?php

namespace App\Repository;

use App\Entity\SmartDeviceData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

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

    // /**
    //  * @return SmartDeviceData[] Returns an array of SmartDeviceData objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?SmartDeviceData
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
