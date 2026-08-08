<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Anokii\Support\Values;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One note in a document's discussion thread.
 *
 * Not revisionable: notes are a flat, append-only thread (newest first),
 * attributed and time-stamped, shared between accounts. This replaces the
 * email/DM back-and-forth with an in-system thread beside the document.
 *
 * Fields (in the automatic _data blob):
 *   document_uuid   the Document this note belongs to
 *   author_uid/_label  who wrote it
 *   body            the note text
 *   created_at      ISO-8601 timestamp (used for newest-first ordering)
 *
 * The label key is author_label (short and always present); the body is read
 * via getBody() from _data.
 */
#[ContentEntityType(id: 'document_note', label: 'Document note', description: 'A note in a document discussion thread.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'author_label')]
final class DocumentNote extends ContentEntityBase
{
    use SovereignMetadataTrait;

    #[Field(read: FieldReadLevel::Public)] public string $document_uuid = '';
    #[Field(read: FieldReadLevel::Public)] public int $author_uid = 0;
    #[Field(read: FieldReadLevel::Public)] public string $author_label = '';
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $body = '';
    #[Field(read: FieldReadLevel::Public)] public string $created_at = '';

    public function getDocumentUuid(): string
    {
        return Values::str($this->get('document_uuid'));
    }

    public function getAuthorUid(): int
    {
        return Values::int($this->get('author_uid'));
    }

    public function getAuthorLabel(): string
    {
        return Values::str($this->get('author_label'));
    }

    public function getBody(): string
    {
        return Values::str($this->get('body'));
    }

    public function getCreatedAt(): string
    {
        return Values::str($this->get('created_at'));
    }

    public function fill(string $documentUuid, int $authorUid, string $authorLabel, string $body, string $createdAt): static
    {
        $this->set('document_uuid', $documentUuid);
        $this->set('author_uid', $authorUid);
        $this->set('author_label', $authorLabel);
        $this->set('body', $body);
        $this->set('created_at', $createdAt);

        return $this;
    }
}
