<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Base for an Anokii instance's per-entity access policy.
 *
 * Every reference instance wrote one near-identical policy per entity
 * (DomainAccessPolicy, ProjectAccessPolicy, DocumentAccessPolicy, ...) with the
 * same four-rule shape:
 *
 *   - view   -> any authenticated workspace account may read; anonymous is
 *               Neutral (workspace-only, fails closed under deny-by-default).
 *   - create -> requires the entity's "edit" permission.
 *   - update -> requires the entity's "edit" permission.
 *   - delete -> requires the entity's "administer" permission.
 *
 * That shape is the common core. The instance subclasses once per entity type
 * and declares only three things: the entity type id, the edit permission, and
 * the administer permission. Everything else is inherited.
 *
 * Implements both {@see AccessPolicyInterface} (entity-level, deny-by-default
 * via isAllowed()) and {@see FieldAccessPolicyInterface} (field-level,
 * open-by-default, only Forbidden restricts). A subclass is therefore the
 * intersection type the framework's EntityAccessHandler discovers via instanceof.
 *
 * This policy does not implement classification or tenant isolation. The
 * framework's cross-cutting classification policy only applies when an entity
 * actually carries a configured classification label. Do not treat this class,
 * or a Neutral field result from it, as evidence of an OCAP boundary.
 *
 * Register a subclass with the framework via the #[PolicyAttribute(entityType:
 * '<id>')] attribute (or #[AccessPolicy] for plugin discovery) on the concrete
 * class, exactly as a hand-written policy would be.
 *
 * Immutable-principal contract: the framework's interfaces type the account
 * parameter as {@see AccountInterface} — PHP forbids narrowing it in an
 * implementation — but they document it as an
 * {@see AuthorizationPrincipalInterface}, and EntityAccessHandler enforces that
 * before any policy runs. This base holds itself to the documented contract
 * anyway (see {@see claims()}): claims are read only off an immutable principal,
 * and any other account denies. A mutable User cannot answer `hasPermission()`
 * at all on framework ≥ alpha.269 — its `roles`/`permissions` are sealed
 * Internal and throw FieldReadDenied — so consulting one could only ever produce
 * an exception, and guessing in its place would be worse than refusing.
 *
 * @api
 */
abstract class AbstractEntityAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    /**
     * The entity type id this policy governs (for example 'document').
     *
     * @api
     */
    abstract protected function entityTypeId(): string;

    /**
     * The permission that gates create and update on this entity type
     * (for example 'edit documents').
     *
     * @api
     */
    abstract protected function editPermission(): string;

    /**
     * The permission that gates delete on this entity type
     * (for example 'administer documents').
     *
     * @api
     */
    abstract protected function administerPermission(): string;

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === $this->entityTypeId();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $principal = $this->claims($account);
        if ($principal === null) {
            return self::mutableAccountDenied();
        }

        if ($operation === 'view') {
            return $this->viewAccess($entity, $principal);
        }

        if ($operation === 'delete') {
            return $principal->hasPermission($this->administerPermission())
                ? AccessResult::allowed($this->administerPermission() . ' may delete')
                : AccessResult::neutral('deleting requires ' . $this->administerPermission());
        }

        // Every remaining operation (update, and any custom write op) is gated
        // by the edit permission. Fails closed via Neutral under the handler's
        // deny-by-default semantics.
        return $principal->hasPermission($this->editPermission())
            ? AccessResult::allowed($this->editPermission() . ' may write')
            : AccessResult::neutral('writing requires ' . $this->editPermission());
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!$this->appliesTo($entityTypeId)) {
            return AccessResult::neutral();
        }

        $principal = $this->claims($account);
        if ($principal === null) {
            return self::mutableAccountDenied();
        }

        return $principal->hasPermission($this->editPermission())
            ? AccessResult::allowed($this->editPermission() . ' may create')
            : AccessResult::neutral('creating requires ' . $this->editPermission());
    }

    /**
     * The account's immutable claims, or null when it is not a principal and
     * therefore cannot be consulted. Every legitimate framework account
     * (AuthorizationPrincipal, AnonymousUser, DevAdminAccount) implements
     * {@see AuthorizationPrincipalInterface}; only the mutable, sealed User
     * entity does not.
     */
    final protected function claims(AccountInterface $account): ?AuthorizationPrincipalInterface
    {
        return $account instanceof AuthorizationPrincipalInterface ? $account : null;
    }

    /**
     * Fail-closed answer for an account that never passed the audited identity
     * boundary. Forbidden rather than Neutral: this is a broken contract, not an
     * absence of opinion, and Neutral could still be OR'd into an Allow by
     * another policy for the same entity type.
     */
    final protected static function mutableAccountDenied(): AccessResult
    {
        return AccessResult::forbidden(
            'Access decisions require an immutable AuthorizationPrincipal; '
            . 'snapshot the account at the audited identity boundary first.',
        );
    }

    /**
     * View access. Any authenticated workspace account may read; anonymous is
     * Neutral so the entity stays workspace-only. A subclass may override to add
     * an explicit view permission gate for staff-only sections.
     *
     * @api
     */
    protected function viewAccess(EntityInterface $entity, AuthorizationPrincipalInterface $account): AccessResult
    {
        return $account->isAuthenticated()
            ? AccessResult::allowed('signed-in workspace users may read')
            : AccessResult::neutral('this entity is workspace-only');
    }

    /**
     * Field-level access. Open-by-default: only an explicit Forbidden from
     * {@see classifiedFieldAccess()} restricts a field. The base returns Neutral
     * for fields this policy does not govern, and defers governed fields to the
     * classification hook.
     *
     * @api
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if (!$this->appliesTo($entity->getEntityTypeId())) {
            return AccessResult::neutral();
        }

        $principal = $this->claims($account);
        if ($principal === null) {
            // Field access is open-by-default, so "no opinion" would EXPOSE the
            // field here. An account whose clearance cannot be read must be
            // Forbidden, not waved through.
            return self::mutableAccountDenied();
        }

        return $this->classifiedFieldAccess($entity, $fieldName, $operation, $principal);
    }

    /**
     * Classification-aware field gate. Override to Forbid sensitive fields based
     * on the entity's classification label and the account's clearance; the
     * default grants everything (Neutral), keeping field access open-by-default.
     *
     * @api
     */
    protected function classifiedFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AuthorizationPrincipalInterface $account,
    ): AccessResult {
        return AccessResult::neutral();
    }
}
