<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * A document in the Anokii Documents workspace.
 *
 * This is an entity-native Anokii tool: it dogfoods the framework's revision
 * system rather than the raw-table shell idiom. The entity is registered
 * revisionable (see the instance's entity-types registration), so EACH VERSION
 * of a document is one revision:
 *
 *   - uploading a new version sets the version fields and saves, which creates
 *     a new revision pointing at the new source/preview files;
 *   - the repository's listRevisions() is the version history;
 *   - setCurrentRevision() / rollback() switch which version is current.
 *
 * File bytes never live in the database. Each version's source (.docx) and
 * preview (.pdf) are stored on the storage volume through the media layer
 * (DocumentStorage), and only their public:// URIs are kept here in the
 * revisioned _data blob.
 *
 * Fields (all carried in the automatic _data blob, snapshotted per revision):
 *   title               document title (the label key)
 *   folder              grouping tag
 *   owner_uid/_label    the document owner (its creator)
 *   source_uri          media URI of this version's source file (.docx)
 *   source_filename     original filename of this version's source
 *   mime_type/size_bytes  of this version's source
 *   preview_uri         media URI of this version's preview (.pdf), if any
 *   version_label       short label for this version (also written to the revision log)
 *   version_author_uid/_label  who created this version
 */
#[ContentEntityType(id: 'document', label: 'Document', description: 'A versioned document with preview, history, and notes.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
final class Document extends ContentEntityBase implements RevisionableInterface
{
    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    public function setTitle(string $title): static
    {
        $this->set('title', $title);

        return $this;
    }

    public function getFolder(): string
    {
        return (string) ($this->get('folder') ?? '');
    }

    public function setFolder(string $folder): static
    {
        $this->set('folder', $folder);

        return $this;
    }

    public function getOwnerUid(): int
    {
        return (int) ($this->get('owner_uid') ?? 0);
    }

    public function getOwnerLabel(): string
    {
        return (string) ($this->get('owner_label') ?? '');
    }

    public function setOwner(int $uid, string $label): static
    {
        $this->set('owner_uid', $uid);
        $this->set('owner_label', $label);

        return $this;
    }

    public function getSourceUri(): string
    {
        return (string) ($this->get('source_uri') ?? '');
    }

    public function getSourceFilename(): string
    {
        return (string) ($this->get('source_filename') ?? '');
    }

    public function getMimeType(): string
    {
        return (string) ($this->get('mime_type') ?? '');
    }

    public function getSizeBytes(): int
    {
        return (int) ($this->get('size_bytes') ?? 0);
    }

    public function getPreviewUri(): string
    {
        return (string) ($this->get('preview_uri') ?? '');
    }

    public function getVersionLabel(): string
    {
        return (string) ($this->get('version_label') ?? '');
    }

    public function getVersionAuthorUid(): int
    {
        return (int) ($this->get('version_author_uid') ?? 0);
    }

    public function getVersionAuthorLabel(): string
    {
        return (string) ($this->get('version_author_label') ?? '');
    }

    public function getUpdatedAt(): string
    {
        return (string) ($this->get('updated_at') ?? '');
    }

    /** When this revision (version) was created, from the revision metadata. */
    public function getRevisionCreatedAt(): ?\DateTimeImmutable
    {
        return $this->revisionMetadata()?->revisionCreatedAt;
    }

    /**
     * Set every field that defines a version, then a save() records it as a new
     * revision (the version label is also mirrored into the revision log).
     */
    public function setVersion(
        string $sourceUri,
        string $sourceFilename,
        string $mimeType,
        int $sizeBytes,
        string $previewUri,
        string $versionLabel,
        int $authorUid,
        string $authorLabel,
    ): static {
        $this->set('source_uri', $sourceUri);
        $this->set('source_filename', $sourceFilename);
        $this->set('mime_type', $mimeType);
        $this->set('size_bytes', $sizeBytes);
        $this->set('preview_uri', $previewUri);
        $this->set('version_label', $versionLabel);
        $this->set('version_author_uid', $authorUid);
        $this->set('version_author_label', $authorLabel);
        $this->setRevisionLog($versionLabel);

        return $this;
    }
}
