<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * A file in the Anokii Drive workspace, an entity-native file record.
 *
 * The entity-type id is `drive_asset` (not `drive_file`): a distinct id lets the
 * framework materialize fresh base + revision tables without colliding with a
 * legacy `drive_file` table an instance may be migrating, the same pattern
 * Identity used (legacy `pillar` -> entity `identity_pillar`). The Drive tool,
 * its routes, and its UI keep the "file" language; only the storage id differs.
 *
 * File bytes never live in the database: they stay on the storage volume through
 * the media layer (DriveStorage, under public://drive/), and only the
 * storage_uri plus listing metadata are kept here. The entity is revisionable
 * so a file gains history and attribution like the other workspace entities.
 *
 * Fields (in the automatic _data blob, snapshotted per revision):
 *   name                display name (original filename)
 *   mime_type           the file's MIME type
 *   kind                coarse UI bucket: pdf | doc | xls | img | gen
 *   size_bytes          byte size
 *   owner_uid/_label    the uploader
 *   folder              grouping tag, e.g. "General"
 *   storage_uri         media URI of the bytes (public://drive/<safe-name>)
 *   uploaded_at         when first uploaded
 *   editor_label        display name of who made the current revision (cache;
 *                       the acting uid is the framework's revision_author,
 *                       alpha.205+ — old revisions keep editor_uid in _data)
 *   updated_at          last-edited stamp
 */
#[ContentEntityType(id: 'drive_asset', label: 'Drive file', description: 'A shared Drive file with attribution and history.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name', revision: 'revision_id')]
final class DriveFile extends ContentEntityBase implements RevisionableInterface
{
    public function getName(): string
    {
        return (string) ($this->get('name') ?? '');
    }

    public function getMimeType(): string
    {
        return (string) ($this->get('mime_type') ?? '');
    }

    public function getKind(): string
    {
        return (string) ($this->get('kind') ?? 'gen');
    }

    public function getSizeBytes(): int
    {
        return (int) ($this->get('size_bytes') ?? 0);
    }

    public function getOwnerUid(): int
    {
        return (int) ($this->get('owner_uid') ?? 0);
    }

    public function getOwnerLabel(): string
    {
        return (string) ($this->get('owner_label') ?? '');
    }

    public function getFolder(): string
    {
        return (string) ($this->get('folder') ?? '');
    }

    public function getStorageUri(): string
    {
        return (string) ($this->get('storage_uri') ?? '');
    }

    public function getUploadedAt(): string
    {
        return (string) ($this->get('uploaded_at') ?? '');
    }

    public function getEditorLabel(): string
    {
        return (string) ($this->get('editor_label') ?? '');
    }

    public function getUpdatedAt(): string
    {
        return (string) ($this->get('updated_at') ?? '');
    }

    /** When this revision was created, from the revision metadata. */
    public function getRevisionCreatedAt(): ?\DateTimeImmutable
    {
        return $this->revisionMetadata()?->revisionCreatedAt;
    }

    /**
     * Populate every field at once (upload / migration path). The caller saves;
     * with revisionDefault the save records the initial revision.
     */
    public function fill(
        string $name,
        string $mimeType,
        string $kind,
        int $sizeBytes,
        int $ownerUid,
        string $ownerLabel,
        string $folder,
        string $storageUri,
        string $uploadedAt,
        string $editorLabel,
        string $updatedAt,
    ): static {
        $this->set('name', $name);
        $this->set('mime_type', $mimeType);
        $this->set('kind', $kind);
        $this->set('size_bytes', $sizeBytes);
        $this->set('owner_uid', $ownerUid);
        $this->set('owner_label', $ownerLabel);
        $this->set('folder', $folder);
        $this->set('storage_uri', $storageUri);
        $this->set('uploaded_at', $uploadedAt);
        $this->set('editor_label', $editorLabel);
        $this->set('updated_at', $updatedAt);

        return $this;
    }

    /** Write a short summary into the revision log. */
    public function recordEdit(string $summary): static
    {
        $this->setRevisionLog($summary);

        return $this;
    }
}
