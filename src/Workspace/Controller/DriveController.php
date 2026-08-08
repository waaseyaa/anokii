<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Access\AccountBoundary;
use Anokii\Entity\DriveFile;
use Anokii\Support\Auth;
use Anokii\Support\Values;
use Anokii\Workspace\Drive\DriveFileService;
use Anokii\Workspace\Drive\DriveStorage;
use Anokii\Workspace\Drive\FileTypes;
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
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * Drive: shared file storage, entity-native. Files are revisionable
 * `drive_asset` entities (bytes stay in the media layer via DriveStorage); the
 * controller consults the workspace AccessPolicy for writes, the same single
 * source of truth as Identity and Documents.
 *
 * Routes are registered ->allowAll() and this controller enforces the session:
 * page requests redirect to /admin/anokii/login, JSON/file actions return 401, and
 * writes the account is not permitted return 403.
 */
final class DriveController
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly DriveFileService $files,
        private readonly DriveStorage $storage,
        private readonly EntityAccessHandler $access,
        private readonly AccountBoundary $accounts,
        private readonly LoggerInterface $logger,
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
        $principal = $this->accounts->principal($user);
        foreach ($this->files->listFiles() as $file) {
            if (!$this->access->check($file, 'view', $principal)->isAllowed()) {
                continue;
            }
            $rows[] = $this->present($file);
        }

        $context = WorkspaceShell::context($this->accounts->identity($user), $user->id(), 'drive') + [
            'files' => $rows,
            'folders' => $this->files->folders(),
        ];

        return new Response(
            $twig->render('anokii/drive.html.twig', $context),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function upload(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            return new JsonResponse(['ok' => false, 'error' => 'No valid file was uploaded.'], 422);
        }
        if (!$this->access->checkCreateAccess('drive_asset', '', $this->accounts->principal($user))->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to upload to Drive.'], 403);
        }

        $originalName = $uploaded->getClientOriginalName();
        $folder = trim((string) $request->request->get('folder', ''));

        try {
            $file = $this->storage->store($uploaded->getPathname(), $originalName, $user->id());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not store the file.'], 500);
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            // One audited identity read serves both attribution stamps.
            $ownerLabel = $this->accounts->label($user);
            $entity = $this->files->createFile(
                name: $originalName,
                mimeType: $file->mimeType,
                kind: FileTypes::kind($file->mimeType, $originalName),
                sizeBytes: $file->size,
                ownerUid: $user->id(),
                ownerLabel: $ownerLabel,
                folder: $folder,
                storageUri: $file->uri,
                uploadedAt: $now,
                editorLabel: $ownerLabel,
                updatedAt: $now,
                revisionLog: 'Uploaded',
            );
        } catch (\Throwable $e) {
            $this->logger->error('drive.upload.metadata_failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'violations' => $e instanceof EntityValidationException ? (string) $e->violations : null,
            ]);
            try {
                $this->storage->delete($file->uri);
            } catch (\Throwable $cleanupError) {
                $this->logger->critical('drive.upload.cleanup_failed', [
                    'error' => $cleanupError->getMessage(),
                    'exception' => $cleanupError::class,
                ]);
                // Preserve the persistence failure in the response. A storage
                // reconciliation scan must surface any cleanup residue.
            }

            return new JsonResponse(['ok' => false, 'error' => 'Could not record the uploaded file.'], 500);
        }

        return new JsonResponse(['ok' => true, 'file' => $this->present($entity)]);
    }

    /** Stream only render-safe raster images inline; every other type downloads. */
    public function download(Request $request, string $id): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }

        $file = $this->files->findByUuid($id);
        if ($file === null) {
            return new Response('Not found', 404);
        }
        if (!$this->access->check($file, 'view', $this->accounts->principal($user))->isAllowed()) {
            return new Response('Not found', 404);
        }

        $path = $this->storage->pathForUri($file->getStorageUri());
        if ($path === null) {
            return new Response('File is missing from storage.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $file->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");
        $safeInline = in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $disposition = !$request->query->getBoolean('dl') && $safeInline
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $file->getName());

        return $response;
    }

    public function delete(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $data = Values::map(json_decode((string) $request->getContent(), true));
        $uuid = Values::trimmed($data['uuid'] ?? null);
        if ($uuid === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing file id.'], 422);
        }

        $file = $this->files->findByUuid($uuid);
        if ($file === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown file.'], 404);
        }
        if (!$this->access->check($file, 'delete', $this->accounts->principal($user))->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to delete Drive files.'], 403);
        }

        try {
            $uri = $this->files->delete($uuid);
            if ($uri !== null) {
                $this->storage->delete($uri);
            }
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Deletion could not be completed; storage reconciliation may be required.'], 500);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Shape a DriveFile entity for the listing template and detail panel,
     * preserving the keys the prototype used so the template is unchanged.
     *
     * @return array<string,mixed>
     */
    private function present(DriveFile $file): array
    {
        $uuid = Values::str($file->get('uuid'));

        return [
            'uuid' => $uuid,
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'kind' => $file->getKind(),
            'size_bytes' => $file->getSizeBytes(),
            'size_human' => FileTypes::humanSize($file->getSizeBytes()),
            'is_image' => $file->getKind() === 'img',
            'owner_label' => $file->getOwnerLabel(),
            'folder' => $file->getFolder(),
            'uploaded_at' => $file->getUploadedAt(),
            // Drive files carry one revision today; the count surfaces in the UI
            // as the version label.
            'version' => 1,
            'view_url' => '/admin/anokii/drive/file/' . $uuid,
            'download_url' => '/admin/anokii/drive/file/' . $uuid . '?dl=1',
        ];
    }
}
