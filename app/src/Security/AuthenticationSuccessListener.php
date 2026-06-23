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

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Attaches a freshly minted refresh token to the JSON login response so the
 * client can later renew its short-lived JWT without re-authenticating.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success', method: 'onAuthenticationSuccess')]
class AuthenticationSuccessListener
{
    public function __construct(private readonly RefreshTokenManager $refreshTokenManager)
    {
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        $data = $event->getData();

        $refreshToken = $this->refreshTokenManager->create($user->getUserIdentifier());

        $data['refresh_token'] = $refreshToken->getToken();
        $data['refresh_token_expiration'] = $refreshToken->getExpiresAt()->getTimestamp();

        $event->setData($data);
    }
}
