<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Entity\ContactSubmission;
use Anokii\Workspace\WorkspaceShell;
use Anokii\Support\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * The Anokii Inbox: contact-form submissions, newest first, with an unread
 * marker and a mark-all-read action. Deliberately just a readable list (v1);
 * the access posture lives in ContactSubmissionAccessPolicy.
 */
final class InboxController
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
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
        $unread = 0;
        foreach ($this->submissions()->findBy([]) as $entity) {
            if (!$entity instanceof ContactSubmission) {
                continue;
            }
            if (!$this->access->check($entity, 'view', $user)->isAllowed()) {
                continue;
            }
            $isRead = $entity->isRead();
            $unread += $isRead ? 0 : 1;
            $rows[] = [
                'email' => $entity->getEmail(),
                'name' => $entity->getName(),
                'org' => $entity->getOrg(),
                'topic' => $entity->getTopic(),
                'message' => $entity->getMessage(),
                'submitted_at' => $entity->getSubmittedAt(),
                'is_read' => $isRead,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b['submitted_at'], $a['submitted_at']));

        $context = WorkspaceShell::context($user, 'inbox') + ['submissions' => $rows, 'unread' => $unread];

        return new Response($twig->render('anokii/inbox.html.twig', $context), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function markAllRead(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Sign in required.'], 401);
        }

        $marked = 0;
        foreach ($this->submissions()->findBy([]) as $entity) {
            if (!$entity instanceof ContactSubmission || $entity->isRead()) {
                continue;
            }
            if (!$this->access->check($entity, 'update', $user)->isAllowed()) {
                return new JsonResponse(['ok' => false, 'error' => 'Marking read requires the manage inbox permission.'], 403);
            }
            $entity->markRead();
            $this->submissions()->save($entity);
            $marked++;
        }

        return new JsonResponse(['ok' => true, 'marked' => $marked]);
    }

    private function submissions(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('Inbox requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('contact_submission');
    }
}
