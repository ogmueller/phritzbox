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

use App\Entity\NotificationChannel;
use App\Repository\AlertRuleRepository;
use App\Repository\NotificationChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/channels')]
class ChannelController extends AbstractController
{
    public function __construct(
        private readonly NotificationChannelRepository $repository,
        private readonly AlertRuleRepository $alertRules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $channels = $this->repository->findBy([], ['id' => 'ASC']);

        return $this->json(array_map(fn (NotificationChannel $c) => $this->serialize($c), $channels));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $channel = new NotificationChannel();
        $error = $this->applyPayload($channel, (array) json_decode($request->getContent(), true));
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $this->json($this->serialize($channel), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $channel = $this->repository->find($id);
        if ($channel === null) {
            return $this->json(['error' => 'Channel not found'], Response::HTTP_NOT_FOUND);
        }

        $error = $this->applyPayload($channel, (array) json_decode($request->getContent(), true));
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json($this->serialize($channel));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $channel = $this->repository->find($id);
        if ($channel === null) {
            return $this->json(['error' => 'Channel not found'], Response::HTTP_NOT_FOUND);
        }

        $inUse = $this->alertRules->countUsingChannel($channel);
        if ($inUse > 0) {
            return $this->json(
                ['error' => \sprintf('Channel is used by %d alert rule(s)', $inUse)],
                Response::HTTP_CONFLICT,
            );
        }

        $this->entityManager->remove($channel);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyPayload(NotificationChannel $channel, array $body): ?string
    {
        $name = mb_trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return 'name is required';
        }

        $type = (string) ($body['type'] ?? '');
        if (!\in_array($type, NotificationChannel::TYPES, true)) {
            return 'type must be one of: '.implode(', ', NotificationChannel::TYPES);
        }

        $target = mb_trim((string) ($body['target'] ?? ''));
        if ($target === '') {
            return 'target is required';
        }

        // Targets that must be URLs vs. free-form identifiers / email.
        $urlTypes = [
            NotificationChannel::TYPE_WEBHOOK,
            NotificationChannel::TYPE_DISCORD,
            NotificationChannel::TYPE_GOTIFY,
            NotificationChannel::TYPE_NTFY,
            NotificationChannel::TYPE_SLACK,
        ];
        if ($type === NotificationChannel::TYPE_EMAIL && !filter_var($target, \FILTER_VALIDATE_EMAIL)) {
            return 'target must be a valid email address';
        }
        if (\in_array($type, $urlTypes, true) && !filter_var($target, \FILTER_VALIDATE_URL)) {
            return 'target must be a valid URL';
        }

        // Channels whose secret (token) is mandatory.
        $secretRequired = [
            NotificationChannel::TYPE_PUSHOVER,
            NotificationChannel::TYPE_TELEGRAM,
            NotificationChannel::TYPE_GOTIFY,
        ];
        $secret = mb_trim((string) ($body['secret'] ?? ''));
        if (\in_array($type, $secretRequired, true) && $secret === '') {
            return 'secret (token) is required for '.$type;
        }
        // ntfy: optional auth token. Everything else ignores the secret.
        if ($secret === '' || (!\in_array($type, $secretRequired, true) && $type !== NotificationChannel::TYPE_NTFY)) {
            $secret = null;
        }

        $channel->setName($name)
            ->setType($type)
            ->setTarget($target)
            ->setSecret($secret)
            ->setEnabled((bool) ($body['enabled'] ?? true));

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(NotificationChannel $channel): array
    {
        return [
            'id' => $channel->getId(),
            'name' => $channel->getName(),
            'type' => $channel->getType(),
            'target' => $channel->getTarget(),
            'secret' => $channel->getSecret(),
            'enabled' => $channel->isEnabled(),
            'createdAt' => $channel->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
