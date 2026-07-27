<?php

declare(strict_types=1);

namespace Anokii\Support;

use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserTwoFactorSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Entity\EntityInterface;

/**
 * Null-object {@see UserInternalFieldReaderInterface} for AuthManager
 * operations that never read user internals (session logout). Keeps the
 * framework's exact session semantics without holding real read authority; any
 * unexpected internal read fails loudly instead of silently succeeding.
 *
 * @internal
 */
final class UnusedInternalFieldReader implements UserInternalFieldReaderInterface
{
    public function credentials(EntityInterface $user): UserCredentialSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }

    public function mailDelivery(EntityInterface $user): UserMailSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }

    public function verification(EntityInterface $user): UserVerificationSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot
    {
        throw new \LogicException('This code path must never read user internals.');
    }
}
