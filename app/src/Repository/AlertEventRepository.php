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

use App\Entity\AlertEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertEvent>
 */
class AlertEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertEvent::class);
    }

    /**
     * Most recent events first.
     *
     * @return AlertEvent[]
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Events of a given metric within a time range whose rule involves at least
     * one of the given devices (subject or compared). Joins the rule, so events
     * whose rule was deleted are excluded (they can't be device-matched).
     *
     * @param list<string> $devices
     *
     * @return list<array{ruleName: string, state: string, sid: string, compareSid: string|null, valueDisplay: float|null, compareDisplay: float|null, createdAt: string}>
     */
    public function findForReport(string $type, string $from, string $to, array $devices): array
    {
        if ($devices === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT e.rule_name AS ruleName, e.state AS state,'
            .' e.value_display AS valueDisplay, e.compare_display AS compareDisplay,'
            .' e.created_at AS createdAt, r.sid AS sid, r.compare_sid AS compareSid'
            .' FROM alert_event e'
            .' JOIN alert_rule r ON r.id = e.rule_id'
            .' WHERE e.type = :type AND e.created_at >= :from AND e.created_at <= :to'
            .' AND (r.sid IN (:devs) OR r.compare_sid IN (:devs))'
            .' ORDER BY e.created_at ASC',
            ['type' => $type, 'from' => $from, 'to' => $to, 'devs' => $devices],
            ['devs' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $r): array => [
            'ruleName' => (string) $r['ruleName'],
            'state' => (string) $r['state'],
            'sid' => (string) $r['sid'],
            'compareSid' => $r['compareSid'] !== null ? (string) $r['compareSid'] : null,
            'valueDisplay' => $r['valueDisplay'] !== null ? (float) $r['valueDisplay'] : null,
            'compareDisplay' => $r['compareDisplay'] !== null ? (float) $r['compareDisplay'] : null,
            'createdAt' => (string) $r['createdAt'],
        ], $rows);
    }
}
