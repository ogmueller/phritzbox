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

namespace App\Security;

use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issues, validates and rotates refresh tokens.
 *
 * A refresh token is a single-use credential: each successful refresh
 * deletes the presented token and issues a new one (rotation), so a
 * leaked-but-already-used token is worthless.
 */
class RefreshTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $repository,
        #[Autowire('%env(int:REFRESH_TOKEN_TTL)%')]
        private readonly int $ttl,
    ) {
    }

    /**
     * Create and persist a fresh refresh token for the given user identifier.
     */
    public function create(string $username): RefreshToken
    {
        $token = new RefreshToken();
        $token->setToken(bin2hex(random_bytes(64)));
        $token->setUsername($username);
        $token->setExpiresAt(new \DateTimeImmutable(\sprintf('+%d seconds', $this->ttl)));

        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    /**
     * Return the stored token if it exists and is still valid, otherwise null.
     */
    public function findValid(string $rawToken): ?RefreshToken
    {
        $token = $this->repository->findOneByToken($rawToken);
        if ($token === null || $token->isExpired()) {
            return null;
        }

        return $token;
    }

    /**
     * Consume a token (single-use) and issue its replacement.
     */
    public function rotate(RefreshToken $token): RefreshToken
    {
        $username = $token->getUsername();
        $this->em->remove($token);
        $this->em->flush();

        return $this->create($username);
    }

    /**
     * Invalidate a token by its raw value (used on logout). No-op if unknown.
     */
    public function invalidate(string $rawToken): void
    {
        $token = $this->repository->findOneByToken($rawToken);
        if ($token !== null) {
            $this->em->remove($token);
            $this->em->flush();
        }
    }
}
