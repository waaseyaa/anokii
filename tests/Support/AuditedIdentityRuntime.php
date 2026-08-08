<?php

declare(strict_types=1);

namespace Anokii\Tests\Support;

use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\AuditedUserInternalFieldReader;
use Waaseyaa\Audit\Bootstrap\AuditedUserSelfProfileReader;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;

/**
 * The real audited identity stack, wired exactly as
 * {@see \Waaseyaa\Audit\AuditServiceProvider} wires it in a booted kernel: the
 * same capability declarations, the same {@see AuditedFieldRead}, the same
 * bootstrap readers. Only the ledger is swapped for an in-memory recorder so a
 * test can assert which audited reads actually happened.
 *
 * Tests use this instead of a hand-rolled principal double so they exercise the
 * genuine capability + reservation path against real sealed User entities. A
 * stub principal would pass even if the audited path were broken.
 */
final class AuditedIdentityRuntime
{
    /** @var list<string> issuer/reason/fields of every reserved privileged read */
    public array $reads = [];

    private readonly CapabilityRegistryInterface $capabilities;

    private readonly StrictPrivilegedReadLedgerInterface $ledger;

    public function __construct()
    {
        $this->capabilities = self::registry();
        $this->ledger = $this->recordingLedger();
    }

    public function principalFactory(): AccountPrincipalFactoryInterface
    {
        return new AccountPrincipalFactory(new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead($this->capabilities, $this->ledger)),
            $this->capabilities,
            'http.identity-bootstrap',
        ));
    }

    public function internalFieldReader(): UserInternalFieldReaderInterface
    {
        return new AuditedUserInternalFieldReader(
            new AuditedFieldRead($this->capabilities, $this->ledger),
            $this->capabilities,
        );
    }

    public function selfProfileReader(): UserSelfProfileReaderInterface
    {
        return new AuditedUserSelfProfileReader(
            new AuditedFieldRead($this->capabilities, $this->ledger),
            $this->capabilities,
        );
    }

    /**
     * The production capability declarations for the User internals this
     * distribution reads. Mirrors AuditServiceProvider::register().
     */
    private static function registry(): CapabilityRegistryInterface
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'http.identity-bootstrap',
            reason: CapabilityReason::SessionBootstrap,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['roles', 'permissions', 'status'],
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            maxTtlSeconds: 60,
            justification: 'Build the immutable HTTP authorization principal after identity resolution.',
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));
        foreach ([
            ['user.credentials', CapabilityReason::CredentialVerification, ['status', 'pass']],
            ['user.mail-delivery', CapabilityReason::MailDelivery, ['name', 'mail']],
            ['user.session-identity', CapabilityReason::SessionBootstrap, ['name', 'mail', 'roles']],
            ['user.maintenance-authorization', CapabilityReason::MaintenanceCli, ['roles', 'permissions']],
        ] as [$issuer, $reason, $fields]) {
            $registry->register(new CapabilityDeclaration(
                issuer: $issuer,
                reason: $reason,
                entityTypes: ['user'],
                bundles: ['user'],
                fields: $fields,
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                maxTtlSeconds: 60,
                justification: 'Exact framework-owned User internal field operation.',
            ));
        }

        return $registry;
    }

    private function recordingLedger(): StrictPrivilegedReadLedgerInterface
    {
        return new class ($this->reads) implements StrictPrivilegedReadLedgerInterface {
            /** @param list<string> $reads */
            public function __construct(private array &$reads) {}

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->reads[] = $descriptor->issuer . '/' . $descriptor->reason->value
                    . '/' . implode(',', $descriptor->fields);

                return new PrivilegedReadReceipt('receipt-' . count($this->reads));
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
    }
}
