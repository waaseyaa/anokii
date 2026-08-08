<?php

declare(strict_types=1);

namespace Anokii\Access;

use Anokii\Support\Auth;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\User\User;

/**
 * The one place in Anokii where a mutable framework {@see User} becomes audited,
 * immutable claims.
 *
 * Framework ≥ alpha.269 seals the User's `roles`, `permissions` and `mail` as
 * Internal and `name` as Protected, and
 * {@see \Waaseyaa\Access\EntityAccessHandler} refuses a mutable entity account
 * outright ("Access decisions require an immutable AuthorizationPrincipal").
 * Both facts have the same consequence: application code may not read a User's
 * claims or identity itself. It must ask a framework authority, and each
 * authority is purpose-limited to one capability reason.
 *
 * This class is that ask, and the only one. Controllers hold an AccountBoundary
 * instead of re-deriving a principal (or a display name) privately, so there is
 * a single audited derivation per concern rather than one per controller:
 *
 *   - {@see principal()} — authorization claims for an access decision, via the
 *     framework's identity-boundary snapshotter
 *     ({@see AccountPrincipalFactoryInterface}, capability
 *     `http.identity-bootstrap`, reason SessionBootstrap). This is the framework's
 *     designated entry point for exactly this conversion: given an entity-backed
 *     account it delegates to the audited bootstrap reader, and refuses to
 *     snapshot one at all when that reader is absent.
 *   - {@see identity()} / {@see label()} — the signed-in account's display
 *     identity, via the authenticated-self-only profile reader plus the same
 *     immutable principal's roles. The profile capability is account-bound and
 *     releases exactly `name` and `mail`; request rendering never borrows the
 *     session-bootstrap, mail-delivery, or maintenance reasons.
 *
 * `maintenanceAuthorization()` is deliberately NOT used for request-time
 * decisions: it is declared under CapabilityReason::MaintenanceCli, and reusing
 * it on the web path would defeat the purpose limitation the capability exists
 * to express.
 *
 * Each call performs one audited read, so a request handler derives what it
 * needs once and reuses it — never inside a per-row loop.
 *
 * Infrastructure faults are not swallowed. A failure to obtain claims must
 * surface, not silently become "no permissions"; the deny-by-default handler
 * would otherwise turn a broken audit ledger into a plausible-looking 403.
 *
 * @api
 */
final readonly class AccountBoundary
{
    public function __construct(
        private AccountPrincipalFactoryInterface $principals,
        private UserSelfProfileReaderInterface $profiles,
    ) {}

    /**
     * The immutable principal to hand to {@see \Waaseyaa\Access\EntityAccessHandler}
     * and to any access policy. Carries the account's roles, permissions and
     * active status as of this call.
     *
     * @api
     */
    public function principal(User $user): AuthorizationPrincipalInterface
    {
        return $this->principals->fromAccount($user);
    }

    /**
     * The signed-in account's audited display identity: name, email address and
     * roles. For rendering the account to itself (the shell chip, the settings
     * form) — not an authorization input.
     *
     * @api
     */
    public function identity(User $user): UserSessionSnapshot
    {
        $principal = $this->principal($user);
        $profile = $this->profiles->read($user, $principal);

        return new UserSessionSnapshot($profile->name, $profile->mail, array_values($principal->getRoles()));
    }

    /**
     * The account's display label, resolved through one audited read.
     * Convenience for the write paths that stamp an editor label on a revision
     * and need nothing else from the identity.
     *
     * @api
     */
    public function label(User $user): string
    {
        return Auth::label($this->identity($user), $user->id());
    }
}
