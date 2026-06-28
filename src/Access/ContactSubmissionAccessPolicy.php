<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access posture for public contact-form submissions (`contact_submission`),
 * the Anokii Inbox tool.
 *
 * Submissions carry personal contact data, so the posture is the tightest in
 * the workspace: any signed-in workspace account may read the inbox; marking
 * read (update) and deleting require `manage inbox`.
 * CREATE IS NEVER GRANTED through the entity gate: the public form writes
 * server-side via the instance's contact submit endpoint, and no workspace
 * surface, agent, or API may mint submissions. The MCP agent scope additionally
 * excludes this type from its read/write allowlists.
 *
 * Standard base shape, with both the edit (update) and administer (delete)
 * gates set to the single `manage inbox` permission, and createAccess overridden
 * to be never-allowed. Everything else fails closed via Neutral.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'contact_submission')]
final class ContactSubmissionAccessPolicy extends AbstractEntityAccessPolicy
{
    protected function entityTypeId(): string
    {
        return 'contact_submission';
    }

    protected function editPermission(): string
    {
        return WorkspaceRoles::MANAGE_INBOX;
    }

    protected function administerPermission(): string
    {
        return WorkspaceRoles::MANAGE_INBOX;
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!$this->appliesTo($entityTypeId)) {
            return AccessResult::neutral();
        }

        // Deliberately never allowed: submissions are created only by the
        // public contact endpoint, server-side, outside the entity gate.
        return AccessResult::neutral('submissions are created by the public contact form only');
    }
}
