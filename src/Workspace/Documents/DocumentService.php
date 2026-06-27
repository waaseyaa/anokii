<?php

declare(strict_types=1);

namespace Anokii\Workspace\Documents;

use Anokii\Entity\Document;
use Anokii\Entity\DocumentNote;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Orchestrates the entity-native Documents tool over the framework's revision
 * system. A document is a revisionable `document` entity; each version is a
 * revision; the notes thread is a `document_note` entity. File bytes go through
 * the media layer (DocumentStorage); previews are produced by Gotenberg for
 * live uploads (seeded versions bring their own .pdf).
 *
 * The public handle for a document is its uuid (stable across versions); the
 * framework revision calls key on the entity's internal id.
 */
final class DocumentService
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly DocumentStorage $storage,
        private readonly GotenbergClient $gotenberg,
    ) {}

    /** @return list<Document> current revision of every document, newest-updated first */
    public function listDocuments(): array
    {
        $docs = [];
        foreach ($this->documents()->findBy([]) as $entity) {
            if ($entity instanceof Document) {
                $docs[] = $entity;
            }
        }
        usort($docs, static fn(Document $a, Document $b) => strcmp($b->getUpdatedAt(), $a->getUpdatedAt()));

        return $docs;
    }

    public function findByUuid(string $uuid): ?Document
    {
        if ($uuid === '') {
            return null;
        }
        foreach ($this->documents()->findBy(['uuid' => $uuid]) as $entity) {
            if ($entity instanceof Document) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Create a document with its first version (revision 1).
     *
     * @param string|null $previewPath Pre-converted .pdf for seeds; when null and
     *   the source is a .docx, a preview is generated via Gotenberg if available.
     */
    public function createDocument(
        string $title,
        string $folder,
        int $ownerUid,
        string $ownerLabel,
        string $sourcePath,
        string $sourceFilename,
        string $versionLabel,
        ?string $previewPath = null,
    ): Document {
        [$sourceUri, $mime, $size, $previewUri] = $this->prepareVersion($sourcePath, $sourceFilename, $previewPath, $ownerUid);

        $doc = new Document();
        $doc->set('uuid', Uuid::v4()->toRfc4122());
        $doc->setTitle($title)->setFolder($folder)->setOwner($ownerUid, $ownerLabel);
        $doc->setVersion($sourceUri, $sourceFilename, $mime, $size, $previewUri, $versionLabel, $ownerUid, $ownerLabel);
        $doc->set('updated_at', gmdate('Y-m-d\TH:i:s\Z'));
        $doc->enforceIsNew();
        $this->documents()->save($doc);

        return $doc;
    }

    /**
     * Add a new version to an existing document (records a new revision).
     */
    public function addVersion(
        string $uuid,
        string $sourcePath,
        string $sourceFilename,
        string $versionLabel,
        int $authorUid,
        string $authorLabel,
        ?string $previewPath = null,
    ): Document {
        $doc = $this->findByUuid($uuid);
        if ($doc === null) {
            throw new \InvalidArgumentException('Unknown document.');
        }

        [$sourceUri, $mime, $size, $previewUri] = $this->prepareVersion($sourcePath, $sourceFilename, $previewPath, $authorUid);

        $doc->setVersion($sourceUri, $sourceFilename, $mime, $size, $previewUri, $versionLabel, $authorUid, $authorLabel);
        $doc->set('updated_at', gmdate('Y-m-d\TH:i:s\Z'));
        $this->documents()->save($doc);

        return $doc;
    }

    /** @return list<Document> every version, newest first (each a revision) */
    public function listVersions(string $uuid): array
    {
        $doc = $this->findByUuid($uuid);
        if ($doc === null) {
            return [];
        }
        $versions = [];
        foreach ($this->documents()->listRevisions((string) $doc->id()) as $rev) {
            if ($rev instanceof Document) {
                $versions[] = $rev;
            }
        }

        return $versions;
    }

    public function loadVersion(string $uuid, int $vid): ?Document
    {
        $doc = $this->findByUuid($uuid);
        if ($doc === null) {
            return null;
        }
        $rev = $this->documents()->loadRevision((string) $doc->id(), $vid);

        return $rev instanceof Document ? $rev : null;
    }

    /** Make an existing version the current one without recording a new revision. */
    public function setCurrentVersion(string $uuid, int $vid): ?Document
    {
        $doc = $this->findByUuid($uuid);
        if ($doc === null) {
            return null;
        }
        $updated = $this->documents()->setCurrentRevision((string) $doc->id(), $vid);

        return $updated instanceof Document ? $updated : null;
    }

    /** Roll back to a version, recording the revert as a fresh version. */
    public function rollbackToVersion(string $uuid, int $vid): ?Document
    {
        $doc = $this->findByUuid($uuid);
        if ($doc === null) {
            return null;
        }
        $reverted = $this->documents()->rollback((string) $doc->id(), $vid);

        return $reverted instanceof Document ? $reverted : null;
    }

    public function addNote(string $uuid, int $authorUid, string $authorLabel, string $body): DocumentNote
    {
        $note = new DocumentNote();
        $note->set('uuid', Uuid::v4()->toRfc4122());
        $note->fill($uuid, $authorUid, $authorLabel, $body, gmdate('Y-m-d\TH:i:s\Z'));
        $note->enforceIsNew();
        $this->notes()->save($note);

        return $note;
    }

    /** @return list<DocumentNote> notes for a document, newest first */
    public function listNotes(string $uuid): array
    {
        $notes = [];
        foreach ($this->notes()->findBy([]) as $note) {
            if ($note instanceof DocumentNote && $note->getDocumentUuid() === $uuid) {
                $notes[] = $note;
            }
        }
        usort($notes, static fn(DocumentNote $a, DocumentNote $b) => strcmp($b->getCreatedAt(), $a->getCreatedAt()));

        return $notes;
    }

    /**
     * Store a version's source bytes and resolve its preview.
     *
     * @return array{0:string,1:string,2:int,3:string} [sourceUri, mime, size, previewUri]
     */
    private function prepareVersion(string $sourcePath, string $sourceFilename, ?string $previewPath, int $ownerUid): array
    {
        $source = $this->storage->store($sourcePath, $sourceFilename, $ownerUid);

        $previewUri = '';
        $previewName = preg_replace('/\.[^.]+$/', '', $sourceFilename) . '.pdf';

        if ($previewPath !== null && is_file($previewPath)) {
            // Seed path: a pre-converted .pdf was supplied.
            $previewUri = $this->storage->store($previewPath, $previewName, $ownerUid)->uri;
        } elseif ($this->isDocx($source->mimeType, $sourceFilename) && $this->gotenberg->isConfigured()) {
            // Live path: convert the .docx to a .pdf preview via Gotenberg. A
            // conversion failure must not lose the document, so the source is
            // already stored and we simply leave the preview empty.
            try {
                $pdf = $this->gotenberg->convertToPdf($sourcePath, $sourceFilename);
                $previewUri = $this->storage->storeBytes($pdf, $previewName, 'application/pdf', $ownerUid)->uri;
            } catch (\Throwable) {
                $previewUri = '';
            }
        }

        return [$source->uri, $source->mimeType, $source->size, $previewUri];
    }

    private function isDocx(string $mime, string $filename): bool
    {
        return $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'docx';
    }

    private function documents(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('DocumentService requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('document');
    }

    private function notes(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('DocumentService requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('document_note');
    }
}
