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

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserController extends AbstractController
{
    private const ALLOWED_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->userRepository->findAll();

        return $this->json(array_map(fn (User $u) => $this->serialize($u), $users));
    }

    #[Route('/me/password', methods: ['PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $body = json_decode($request->getContent(), true);
        $currentPassword = $body['currentPassword'] ?? '';
        $newPassword = $body['newPassword'] ?? '';

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['error' => 'Current password is incorrect'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($newPassword)) {
            return $this->json(['error' => 'New password is required'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        return $this->json(['message' => 'Password changed']);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (empty($body['username']) || empty($body['email']) || empty($body['password'])) {
            return $this->json(['error' => 'username, email and password are required'], Response::HTTP_BAD_REQUEST);
        }

        $roles = $body['roles'] ?? [];
        if (array_diff($roles, self::ALLOWED_ROLES)) {
            return $this->json(['error' => 'Invalid roles'], Response::HTTP_BAD_REQUEST);
        }

        $user = new User();
        $user->setUsername($body['username'])
             ->setEmail($body['email'])
             ->setRoles($roles)
             ->setPassword($this->passwordHasher->hashPassword($user, $body['password']));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json($this->serialize($user), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if ($user === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);

        if (!empty($body['username'])) {
            $user->setUsername($body['username']);
        }
        if (!empty($body['email'])) {
            $user->setEmail($body['email']);
        }
        if (!empty($body['roles'])) {
            if (array_diff($body['roles'], self::ALLOWED_ROLES)) {
                return $this->json(['error' => 'Invalid roles'], Response::HTTP_BAD_REQUEST);
            }
            $user->setRoles($body['roles']);
        }
        if (!empty($body['password'])) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $body['password']));
        }

        $this->entityManager->flush();

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->getId() === $id) {
            return $this->json(['error' => 'Cannot delete your own account'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->find($id);

        if ($user === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
