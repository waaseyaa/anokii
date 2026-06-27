<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Access posture for Drive files (drive_asset).
 *
 * Drive is a shared bucket (standard workspace shape via the shared Anokii
 * base): any signed-in account may read every file; uploading or editing
 * (create/update) requires `edit drive`; delete requires `administer drive`
 * (the bytes are removed and not recoverable); anonymous and everything else
 * fails closed.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'drive_asset')]
final class DriveFileAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'drive_asset';
    }

    protected function editPermission(): string
    {
        return WorkspaceRoles::EDIT_DRIVE;
    }

    protected function administerPermission(): string
    {
        return WorkspaceRoles::ADMINISTER_DRIVE;
    }
}
