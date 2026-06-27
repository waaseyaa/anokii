<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Access posture for Documents (document).
 *
 * Standard workspace shape (via the shared Anokii base): any signed-in account
 * may read; create/update (add a version, set-current, rollback) require
 * `edit documents`; delete requires `administer documents`; anonymous and
 * everything else fails closed.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'document')]
final class DocumentAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'document';
    }

    protected function editPermission(): string
    {
        return WorkspaceRoles::EDIT_DOCUMENTS;
    }

    protected function administerPermission(): string
    {
        return WorkspaceRoles::ADMINISTER_DOCUMENTS;
    }
}
