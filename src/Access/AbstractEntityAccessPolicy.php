<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
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
 * Classification-awareness: {@see fieldAccess()} is open-by-default and delegates
 * to {@see classifiedFieldAccess()}, which a subclass overrides to forbid
 * specific fields based on the entity's classification label and the account's
 * clearance. The base default grants everything, so classification gating is an
 * opt-in extension point, not a hidden behavior.
 *
 * Register a subclass with the framework via the #[PolicyAttribute(entityType:
 * '<id>')] attribute (or #[AccessPolicy] for plugin discovery) on the concrete
 * class, exactly as a hand-written policy would be.
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
        if ($operation === 'view') {
            return $this->viewAccess($entity, $account);
        }

        if ($operation === 'delete') {
            return $account->hasPermission($this->administerPermission())
                ? AccessResult::allowed($this->administerPermission() . ' may delete')
                : AccessResult::neutral('deleting requires ' . $this->administerPermission());
        }

        // Every remaining operation (update, and any custom write op) is gated
        // by the edit permission. Fails closed via Neutral under the handler's
        // deny-by-default semantics.
        return $account->hasPermission($this->editPermission())
            ? AccessResult::allowed($this->editPermission() . ' may write')
            : AccessResult::neutral('writing requires ' . $this->editPermission());
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!$this->appliesTo($entityTypeId)) {
            return AccessResult::neutral();
        }

        return $account->hasPermission($this->editPermission())
            ? AccessResult::allowed($this->editPermission() . ' may create')
            : AccessResult::neutral('creating requires ' . $this->editPermission());
    }

    /**
     * View access. Any authenticated workspace account may read; anonymous is
     * Neutral so the entity stays workspace-only. A subclass may override to add
     * an explicit view permission gate for staff-only sections.
     *
     * @api
     */
    protected function viewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
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

        return $this->classifiedFieldAccess($entity, $fieldName, $operation, $account);
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
        AccountInterface $account,
    ): AccessResult {
        return AccessResult::neutral();
    }
}
