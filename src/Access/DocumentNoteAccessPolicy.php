<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Access posture for Document notes (document_note), the discussion thread.
 *
 * A note shares the Documents permissions (via the shared Anokii base): any
 * signed-in account may read; posting a note (create/update) requires
 * `edit documents`; removing one requires `administer documents`; anonymous and
 * everything else fails closed.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'document_note')]
final class DocumentNoteAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'document_note';
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
