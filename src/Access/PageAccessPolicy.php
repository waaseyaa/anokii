<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access posture for public marketing pages (`page`), the Anokii Pages tool.
 *
 * Standard workspace shape (via the shared Anokii base) for read, edit (save a
 * draft revision), and delete, plus one extra gate on top: moving the live view
 * (the `publish` / rollback operation) is a separate, higher bar that requires
 * `publish pages`, not merely `edit pages`. The base handles view (any signed-in
 * account), update/create (`edit pages`), and delete (`administer pages`); this
 * subclass adds the `publish` case before delegating.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'page')]
final class PageAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'page';
    }

    protected function editPermission(): string
    {
        return WorkspaceRoles::EDIT_PAGES;
    }

    protected function administerPermission(): string
    {
        return WorkspaceRoles::ADMINISTER_PAGES;
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // publish / rollback: move the live (published) pointer. Higher bar than
        // a draft edit; everything else follows the standard base shape.
        if ($operation === 'publish') {
            return $account->hasPermission(WorkspaceRoles::PUBLISH_PAGES)
                ? AccessResult::allowed('publish pages may change the live view')
                : AccessResult::neutral('publishing a page requires publish pages');
        }

        return parent::access($entity, $operation, $account);
    }
}
