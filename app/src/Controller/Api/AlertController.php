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

namespace App\Controller\Api;

use App\Entity\AlertRule;
use App\Repository\AlertRuleRepository;
use App\Repository\NotificationChannelRepository;
use App\Service\AlertEvaluationService;
use App\Service\MetricUnits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/alerts')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly AlertRuleRepository $repository,
        private readonly NotificationChannelRepository $channels,
        private readonly EntityManagerInterface $entityManager,
        private readonly AlertEvaluationService $evaluationService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rules = $this->repository->findBy([], ['id' => 'ASC']);

        return $this->json(array_map(fn (AlertRule $r) => $this->serialize($r), $rules));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $rule = new AlertRule();
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Invalid request body'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyPayload($rule, $body);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $this->json($this->serialize($rule), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $rule = $this->repository->find($id);
        if ($rule === null) {
            return $this->json(['error' => 'Alert rule not found'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Invalid request body'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyPayload($rule, $body);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json($this->serialize($rule));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $rule = $this->repository->find($id);
        if ($rule === null) {
            return $this->json(['error' => 'Alert rule not found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($rule);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/toggle', methods: ['POST'])]
    public function toggle(int $id): JsonResponse
    {
        $rule = $this->repository->find($id);
        if ($rule === null) {
            return $this->json(['error' => 'Alert rule not found'], Response::HTTP_NOT_FOUND);
        }

        $rule->setEnabled(!$rule->isEnabled());
        $this->entityManager->flush();

        return $this->json($this->serialize($rule));
    }

    #[Route('/{id}/test', methods: ['POST'])]
    public function test(int $id): JsonResponse
    {
        $rule = $this->repository->find($id);
        if ($rule === null) {
            return $this->json(['error' => 'Alert rule not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->evaluationService->sendTest($rule);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['status' => 'sent']);
    }

    /**
     * Validate and apply a full payload onto the rule. Returns an error message or null.
     *
     * @param array<string, mixed> $body
     */
    private function applyPayload(AlertRule $rule, array $body): ?string
    {
        $name = mb_trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return 'name is required';
        }

        $mode = (string) ($body['mode'] ?? '');
        if (!\in_array($mode, AlertRule::MODES, true)) {
            return 'mode must be one of: '.implode(', ', AlertRule::MODES);
        }

        $sid = mb_trim((string) ($body['sid'] ?? ''));
        if ($sid === '') {
            return 'sid is required';
        }

        $type = (string) ($body['type'] ?? '');
        if (!MetricUnits::isValidType($type)) {
            return 'type must be one of: '.implode(', ', MetricUnits::TYPES);
        }

        $operator = (string) ($body['operator'] ?? '');
        if (!\in_array($operator, AlertRule::OPERATORS, true)) {
            return 'operator must be one of: '.implode(', ', AlertRule::OPERATORS);
        }

        $channelIds = $body['channelIds'] ?? null;
        if (!\is_array($channelIds) || $channelIds === []) {
            return 'at least one channel is required';
        }
        $resolvedChannels = [];
        foreach ($channelIds as $cid) {
            if (!is_numeric($cid)) {
                return 'channelIds must be channel IDs';
            }
            $channel = $this->channels->find((int) $cid);
            if ($channel === null) {
                return 'channelIds references a non-existent channel';
            }
            $resolvedChannels[] = $channel;
        }

        if ($mode === AlertRule::MODE_THRESHOLD) {
            if (!isset($body['threshold']) || !is_numeric($body['threshold'])) {
                return 'threshold is required for threshold rules';
            }
            $rule->setThreshold((float) $body['threshold']);
            $rule->setCompareSid(null);
            $rule->setCompareType(null);
        } else {
            $compareSid = mb_trim((string) ($body['compareSid'] ?? ''));
            $compareType = (string) ($body['compareType'] ?? '');
            if ($compareSid === '') {
                return 'compareSid is required for comparison rules';
            }
            if (!MetricUnits::isValidType($compareType)) {
                return 'compareType must be one of: '.implode(', ', MetricUnits::TYPES);
            }
            $rule->setCompareSid($compareSid);
            $rule->setCompareType($compareType);
            $rule->setThreshold(null);
            $rule->setCompareOffset(isset($body['compareOffset']) && is_numeric($body['compareOffset']) ? (float) $body['compareOffset'] : 0.0);
        }

        $duration = (int) ($body['durationMinutes'] ?? 0);
        $cooldown = (int) ($body['cooldownMinutes'] ?? 60);
        if ($duration < 0 || $cooldown < 0) {
            return 'durationMinutes and cooldownMinutes must not be negative';
        }

        $rule->setName($name)
            ->setEnabled((bool) ($body['enabled'] ?? true))
            ->setMode($mode)
            ->setSid($sid)
            ->setType($type)
            ->setOperator($operator)
            ->setDurationMinutes($mode === AlertRule::MODE_THRESHOLD ? $duration : 0)
            ->setCooldownMinutes($cooldown);

        $rule->clearChannels();
        foreach ($resolvedChannels as $channel) {
            $rule->addChannel($channel);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AlertRule $rule): array
    {
        return [
            'id' => $rule->getId(),
            'name' => $rule->getName(),
            'enabled' => $rule->isEnabled(),
            'mode' => $rule->getMode(),
            'sid' => $rule->getSid(),
            'type' => $rule->getType(),
            'operator' => $rule->getOperator(),
            'threshold' => $rule->getThreshold(),
            'compareSid' => $rule->getCompareSid(),
            'compareType' => $rule->getCompareType(),
            'compareOffset' => $rule->getCompareOffset(),
            'durationMinutes' => $rule->getDurationMinutes(),
            'channelIds' => array_map(static fn ($c) => $c->getId(), $rule->getChannels()->toArray()),
            'channelNames' => array_map(static fn ($c) => $c->getName(), $rule->getChannels()->toArray()),
            'cooldownMinutes' => $rule->getCooldownMinutes(),
            'lastState' => $rule->getLastState(),
            'lastTriggeredAt' => $rule->getLastTriggeredAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $rule->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
