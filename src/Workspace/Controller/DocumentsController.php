<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Entity\Document;
use Anokii\Entity\DocumentNote;
use Anokii\Support\Auth;
use Anokii\Workspace\Documents\DocumentService;
use Anokii\Workspace\Documents\DocumentStorage;
use Anokii\Workspace\WorkspaceShell;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * Documents: an entity-native Anokii tool. A document is a revisionable
 * `document` entity (each version is a revision); the detail view is a
 * two-column layout (preview left; version history and notes right).
 *
 * Routes are registered ->allowAll() and this controller enforces the session:
 * page requests redirect to /admin/anokii/login, JSON/file actions return 401.
 */
final class DocumentsController
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly DocumentService $documents,
        private readonly DocumentStorage $storage,
        private readonly EntityAccessHandler $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Anokii unavailable: Twig is not initialised.', 500);
        }

        $rows = [];
        foreach ($this->documents->listDocuments() as $doc) {
            $rows[] = $this->presentRow($doc);
        }

        $context = WorkspaceShell::context($user, 'documents') + ['documents' => $rows];

        return new Response($twig->render('anokii/documents.html.twig', $context), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Anokii unavailable: Twig is not initialised.', 500);
        }

        $doc = $this->documents->findByUuid($uuid);
        if ($doc === null) {
            return new Response('Document not found.', 404);
        }

        $versions = [];
        foreach ($this->documents->listVersions($uuid) as $rev) {
            $versions[] = $this->presentVersion($uuid, $rev);
        }
        $notes = [];
        foreach ($this->documents->listNotes($uuid) as $note) {
            $notes[] = $this->presentNote($note);
        }

        $context = WorkspaceShell::context($user, 'documents') + [
            'doc' => $this->presentRow($doc),
            'versions' => $versions,
            'notes' => $notes,
        ];

        return new Response($twig->render('anokii/document.html.twig', $context), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Create a new document from an uploaded .docx (its first version). */
    public function create(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            return new JsonResponse(['ok' => false, 'error' => 'No valid file was uploaded.'], 422);
        }
        if (!$this->access->checkCreateAccess('document', '', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to create documents.'], 403);
        }

        $title = trim((string) $request->request->get('title', '')) ?: pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME);
        $folder = trim((string) $request->request->get('folder', ''));
        $label = trim((string) $request->request->get('version_label', '')) ?: 'Initial version';

        try {
            $doc = $this->documents->createDocument(
                title: $title,
                folder: $folder,
                ownerUid: $user->id(),
                ownerLabel: Auth::label($user),
                sourcePath: $uploaded->getPathname(),
                sourceFilename: $uploaded->getClientOriginalName(),
                versionLabel: $label,
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not create the document.'], 500);
        }

        return new JsonResponse(['ok' => true, 'redirect' => '/admin/anokii/documents/' . $this->uuidOf($doc)]);
    }

    /** Upload a new version of an existing document (records a revision). */
    public function uploadVersion(Request $request, string $uuid): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            return new JsonResponse(['ok' => false, 'error' => 'No valid file was uploaded.'], 422);
        }

        $doc = $this->documents->findByUuid($uuid);
        if ($doc === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown document.'], 404);
        }
        if (!$this->access->check($doc, 'update', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to edit documents.'], 403);
        }

        $label = trim((string) $request->request->get('version_label', '')) ?: ('Version uploaded ' . gmdate('Y-m-d'));

        try {
            $this->documents->addVersion(
                uuid: $uuid,
                sourcePath: $uploaded->getPathname(),
                sourceFilename: $uploaded->getClientOriginalName(),
                versionLabel: $label,
                authorUid: $user->id(),
                authorLabel: Auth::label($user),
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not add the version.'], 500);
        }

        return new JsonResponse(['ok' => true, 'redirect' => '/admin/anokii/documents/' . $uuid]);
    }

    public function setCurrent(Request $request, string $uuid): Response
    {
        return $this->switchVersion($request, $uuid, false);
    }

    public function rollback(Request $request, string $uuid): Response
    {
        return $this->switchVersion($request, $uuid, true);
    }

    public function addNote(Request $request, string $uuid): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Write a note first.'], 422);
        }
        if ($this->documents->findByUuid($uuid) === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown document.'], 404);
        }
        if (!$this->access->checkCreateAccess('document_note', '', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to post notes.'], 403);
        }

        $note = $this->documents->addNote($uuid, $user->id(), Auth::label($user), $body);

        return new JsonResponse(['ok' => true, 'note' => $this->presentNote($note)]);
    }

    /** Stream a version's source (.docx) or preview (.pdf); inline unless ?dl=1. */
    public function download(Request $request, string $uuid, string $vid, string $kind): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }

        $version = $this->documents->loadVersion($uuid, (int) $vid);
        if ($version === null) {
            return new Response('Version not found.', 404);
        }

        $isPreview = $kind === 'preview';
        $uri = $isPreview ? $version->getPreviewUri() : $version->getSourceUri();
        if ($uri === '') {
            return new Response('No file for this version.', 404);
        }
        $path = $this->storage->pathForUri($uri);
        if ($path === null) {
            return new Response('File is missing from storage.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $isPreview ? 'application/pdf' : $version->getMimeType());
        $downloadName = $isPreview
            ? preg_replace('/\.[^.]+$/', '', $version->getSourceFilename()) . '.pdf'
            : $version->getSourceFilename();
        $disposition = $request->query->getBoolean('dl')
            ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
            : ResponseHeaderBag::DISPOSITION_INLINE;
        $response->setContentDisposition($disposition, (string) $downloadName);

        return $response;
    }

    private function switchVersion(Request $request, string $uuid, bool $rollback): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];
        $vid = (int) ($data['vid'] ?? 0);
        if ($vid <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'Missing version id.'], 422);
        }

        $doc = $this->documents->findByUuid($uuid);
        if ($doc === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown document.'], 404);
        }
        if (!$this->access->check($doc, 'update', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to edit documents.'], 403);
        }

        try {
            $result = $rollback
                ? $this->documents->rollbackToVersion($uuid, $vid)
                : $this->documents->setCurrentVersion($uuid, $vid);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not switch version.'], 500);
        }
        if ($result === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown document.'], 404);
        }

        return new JsonResponse(['ok' => true, 'redirect' => '/admin/anokii/documents/' . $uuid]);
    }

    /** @return array<string,mixed> */
    private function presentRow(Document $doc): array
    {
        $uuid = $this->uuidOf($doc);

        return [
            'uuid' => $uuid,
            'title' => $doc->getTitle(),
            'folder' => $doc->getFolder(),
            'owner_label' => $doc->getOwnerLabel(),
            'current_label' => $doc->getVersionLabel(),
            'current_author' => $doc->getVersionAuthorLabel(),
            'current_vid' => (int) $doc->getRevisionId(),
            'updated' => $this->stamp($doc),
            'has_preview' => $doc->getPreviewUri() !== '',
            'href' => '/admin/anokii/documents/' . $uuid,
            'preview_url' => '/admin/anokii/documents/' . $uuid . '/file/' . (int) $doc->getRevisionId() . '/preview',
            'source_url' => '/admin/anokii/documents/' . $uuid . '/file/' . (int) $doc->getRevisionId() . '/source?dl=1',
        ];
    }

    /** @return array<string,mixed> */
    private function presentVersion(string $uuid, Document $rev): array
    {
        $vid = (int) $rev->getRevisionId();
        $created = $rev->getRevisionCreatedAt();

        return [
            'vid' => $vid,
            'label' => $rev->getVersionLabel(),
            'author' => $rev->getVersionAuthorLabel(),
            'created' => $created?->format('M j, Y g:i A') . ' UTC',
            'is_current' => $rev->isCurrentRevision(),
            'filename' => $rev->getSourceFilename(),
            'has_preview' => $rev->getPreviewUri() !== '',
            'preview_url' => '/admin/anokii/documents/' . $uuid . '/file/' . $vid . '/preview',
            'source_url' => '/admin/anokii/documents/' . $uuid . '/file/' . $vid . '/source?dl=1',
        ];
    }

    /** @return array<string,mixed> */
    private function presentNote(DocumentNote $note): array
    {
        $created = $note->getCreatedAt();
        $ts = strtotime($created) ?: time();

        return [
            'author' => $note->getAuthorLabel(),
            'created' => gmdate('M j, Y g:i A', $ts) . ' UTC',
            'body' => $note->getBody(),
        ];
    }

    private function stamp(Document $doc): string
    {
        $created = $doc->getRevisionCreatedAt();
        if ($created !== null) {
            return $created->format('M j, Y');
        }
        $updated = $doc->getUpdatedAt();

        return $updated !== '' ? gmdate('M j, Y', strtotime($updated) ?: time()) : '';
    }

    private function uuidOf(Document $doc): string
    {
        return (string) ($doc->get('uuid') ?? '');
    }
}
