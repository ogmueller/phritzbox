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

use App\Repository\UserRepository;
use App\Security\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManager $refreshTokenManager,
        private readonly UserRepository $users,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    /**
     * Exchange a valid refresh token for a new JWT (and a rotated refresh token).
     *
     * Public endpoint: the caller is, by definition, holding an expired/absent
     * access token, so it must not require JWT authentication.
     */
    #[Route('/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $rawToken = \is_array($body) ? ($body['refresh_token'] ?? null) : null;

        if (!\is_string($rawToken) || $rawToken === '') {
            return $this->json(['error' => 'Missing refresh token'], Response::HTTP_BAD_REQUEST);
        }

        $stored = $this->refreshTokenManager->findValid($rawToken);
        if ($stored === null) {
            return $this->json(['error' => 'Invalid or expired refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->users->findOneBy(['username' => $stored->getUsername()]);
        if ($user === null) {
            // User was deleted after the token was issued — drop the orphan token.
            $this->refreshTokenManager->invalidate($rawToken);

            return $this->json(['error' => 'Invalid or expired refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        $rotated = $this->refreshTokenManager->rotate($stored);

        return $this->json([
            'token' => $this->jwtManager->create($user),
            'refresh_token' => $rotated->getToken(),
            'refresh_token_expiration' => $rotated->getExpiresAt()->getTimestamp(),
        ]);
    }

    /**
     * Invalidate a refresh token (best-effort) so it can no longer be used.
     */
    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $rawToken = \is_array($body) ? ($body['refresh_token'] ?? null) : null;

        if (\is_string($rawToken) && $rawToken !== '') {
            $this->refreshTokenManager->invalidate($rawToken);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
