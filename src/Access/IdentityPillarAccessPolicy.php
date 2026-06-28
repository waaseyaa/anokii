<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Access posture for Identity Workspace pillars (identity_pillar).
 *
 * Standard workspace shape (via the shared Anokii base): any signed-in account
 * may read; create/update require `edit identity`; delete requires
 * `administer identity`; anonymous and everything else fails closed.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'identity_pillar')]
final class IdentityPillarAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'identity_pillar';
    }

    protected function editPermission(): string
    {
        return WorkspaceRoles::EDIT_IDENTITY;
    }

    protected function administerPermission(): string
    {
        return WorkspaceRoles::ADMINISTER_IDENTITY;
    }
}
