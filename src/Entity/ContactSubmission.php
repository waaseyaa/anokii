<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * One submission from the public contact form.
 *
 * Not revisionable: a submission is an immutable inbound record (the only
 * mutation is the read flag). Created exclusively by the public contact-form
 * submit endpoint (instance-side); read in the Anokii Inbox by workspace
 * accounts. The MCP agent scope deliberately excludes this type (personal
 * contact data).
 *
 * Fields (in the automatic _data blob):
 *   email         the sender's address (the only required field; validated)
 *   name          optional sender name
 *   org           optional Nation / organization
 *   topic         optional dropdown value (whitelisted slug)
 *   message       optional free text (length-capped)
 *   submitted_at  ISO-8601 UTC timestamp (newest-first ordering)
 *   source_path   the public page that posted (e.g. /contact)
 *   is_read       0 until staff mark the inbox read
 *
 * No IP is stored on the entity in any form; rate limiting keeps only a
 * salted hash in its own non-entity table.
 */
#[ContentEntityType(id: 'contact_submission', label: 'Contact submission', description: 'An inbound submission from the public contact form.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'email')]
final class ContactSubmission extends ContentEntityBase
{
    public function getEmail(): string
    {
        return (string) ($this->get('email') ?? '');
    }

    public function getName(): string
    {
        return (string) ($this->get('name') ?? '');
    }

    public function getOrg(): string
    {
        return (string) ($this->get('org') ?? '');
    }

    public function getTopic(): string
    {
        return (string) ($this->get('topic') ?? '');
    }

    public function getMessage(): string
    {
        return (string) ($this->get('message') ?? '');
    }

    public function getSubmittedAt(): string
    {
        return (string) ($this->get('submitted_at') ?? '');
    }

    public function getSourcePath(): string
    {
        return (string) ($this->get('source_path') ?? '');
    }

    public function isRead(): bool
    {
        return (int) ($this->get('is_read') ?? 0) === 1;
    }

    public function markRead(): static
    {
        $this->set('is_read', 1);

        return $this;
    }

    /** Populate every field at creation; the caller saves. */
    public function fill(
        string $email,
        string $name,
        string $org,
        string $topic,
        string $message,
        string $submittedAt,
        string $sourcePath,
    ): static {
        $this->set('email', $email);
        $this->set('name', $name);
        $this->set('org', $org);
        $this->set('topic', $topic);
        $this->set('message', $message);
        $this->set('submitted_at', $submittedAt);
        $this->set('source_path', $sourcePath);
        $this->set('is_read', 0);

        return $this;
    }
}
