<?php

declare(strict_types=1);

namespace Anokii\Workspace\Drive;

use Anokii\Entity\DriveFile;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Orchestrates the entity-native Drive over the framework revision system. A
 * Drive file is a revisionable `drive_asset` entity; bytes stay in the media
 * layer (DriveStorage), only metadata + the storage URI live in the entity.
 *
 * The same read/write surface as a raw-table repository, now on a registered
 * entity that falls under the workspace AccessPolicy layer.
 */
final class DriveFileService
{
    public const string DEFAULT_FOLDER = 'General';

    public function __construct(private readonly ?EntityTypeManager $entityTypeManager) {}

    /** @return list<DriveFile> all files, newest upload first */
    public function listFiles(): array
    {
        $files = [];
        foreach ($this->files()->findBy([]) as $entity) {
            if ($entity instanceof DriveFile) {
                $files[] = $entity;
            }
        }
        usort($files, static fn(DriveFile $a, DriveFile $b) => strcmp($b->getUploadedAt(), $a->getUploadedAt()));

        return $files;
    }

    public function findByUuid(string $uuid): ?DriveFile
    {
        if ($uuid === '') {
            return null;
        }
        foreach ($this->files()->findBy(['uuid' => $uuid]) as $entity) {
            if ($entity instanceof DriveFile) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Create a file with its initial revision. Used by upload, seed, and
     * migration. The caller supplies the attribution stamp so a migrated file
     * keeps its original uploader and time.
     */
    public function createFile(
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
        string $revisionLog,
        ?string $uuid = null,
    ): DriveFile {
        $folder = trim($folder) !== '' ? trim($folder) : self::DEFAULT_FOLDER;

        $file = new DriveFile();
        // Migration preserves the legacy uuid so existing links stay valid; a
        // fresh upload gets a new one.
        $file->set('uuid', $uuid !== null && $uuid !== '' ? $uuid : Uuid::v4()->toRfc4122());
        $file->fill(
            $name,
            $mimeType,
            $kind,
            $sizeBytes,
            $ownerUid,
            $ownerLabel,
            $folder,
            $storageUri,
            $uploadedAt,
            $editorLabel,
            $updatedAt,
        );
        $file->recordEdit($revisionLog);
        $file->enforceIsNew();
        $this->files()->save($file);

        return $file;
    }

    /**
     * Delete a file entity, returning its storage URI so the caller can remove
     * the bytes, or null if the uuid is unknown.
     */
    public function delete(string $uuid): ?string
    {
        $file = $this->findByUuid($uuid);
        if ($file === null) {
            return null;
        }
        $uri = $file->getStorageUri();
        $this->files()->delete($file);

        return $uri;
    }

    /**
     * Distinct folder names, alphabetical.
     *
     * @return list<string>
     */
    public function folders(): array
    {
        $folders = [];
        foreach ($this->listFiles() as $file) {
            $folder = $file->getFolder();
            if ($folder !== '') {
                $folders[$folder] = true;
            }
        }
        $names = array_keys($folders);
        sort($names);

        return $names;
    }

    public function count(): int
    {
        return count($this->listFiles());
    }

    private function files(): EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('DriveFileService requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('drive_asset');
    }
}
