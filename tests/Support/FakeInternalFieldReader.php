<?php

declare(strict_types=1);

namespace Anokii\Tests\Support;

use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserTwoFactorSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Entity\EntityInterface;

/**
 * Canned-snapshot {@see UserInternalFieldReaderInterface} double for unit
 * tests below the audit integration boundary. Only the snapshots a test
 * injects are servable; every other authority throws so an unexpected
 * internal read fails loudly.
 */
final readonly class FakeInternalFieldReader implements UserInternalFieldReaderInterface
{
    public function __construct(
        private ?UserAuthorizationSnapshot $authorization = null,
        private ?UserCredentialSnapshot $credentials = null,
    ) {}

    public function credentials(EntityInterface $user): UserCredentialSnapshot
    {
        return $this->credentials ?? throw new \LogicException('No credentials snapshot injected.');
    }

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot
    {
        throw new \LogicException('Unexpected twoFactor() read.');
    }

    public function mailDelivery(EntityInterface $user): UserMailSnapshot
    {
        throw new \LogicException('Unexpected mailDelivery() read.');
    }

    public function verification(EntityInterface $user): UserVerificationSnapshot
    {
        throw new \LogicException('Unexpected verification() read.');
    }

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot
    {
        throw new \LogicException('Unexpected sessionIdentity() read.');
    }

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot
    {
        return $this->authorization ?? throw new \LogicException('No authorization snapshot injected.');
    }
}
