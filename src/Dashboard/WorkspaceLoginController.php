<?php

declare(strict_types=1);

namespace Anokii\Dashboard;

use Anokii\Auth\SetupTokenRepository;
use Anokii\Support\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Canonical login surface for the workspace admin tier (member accounts): a
 * JSON sign-in plus a one-time set-password flow, so an invited account holder
 * ({@see \Anokii\Admin\InviteHandler}) sets their own initial password. The
 * single-admin tier uses {@see AdminLoginController} (password create-admin)
 * instead; this is the invite-link tier.
 *
 * One implementation, pathed and templated by config: an install passes its own
 * login path, home path, set-password path, and its branded Twig templates. The
 * gate (authenticated vs not) is the shared {@see DashboardGate}; the workspace
 * tool pages enforce authentication via requirePage().
 *
 * @api
 */
final class WorkspaceLoginController extends DashboardGate
{
    /**
     * @param string $loginPathValue       this login surface's own path (e.g. /admin/anokii/login)
     * @param string $homePath             where a successful / already-authenticated sign-in lands
     * @param string $loginTemplate        Twig template for the sign-in page (install-branded)
     * @param string $setPasswordTemplate  Twig template for the set-password page (install-branded)
     * @param int    $minPasswordLength    minimum length enforced on set-password
     */
    public function __construct(
        ?EntityTypeManager $entityTypeManager,
        private readonly SetupTokenRepository $tokens,
        private readonly string $loginPathValue,
        private readonly string $homePath,
        private readonly string $loginTemplate,
        private readonly string $setPasswordTemplate,
        private readonly int $minPasswordLength = 10,
    ) {
        parent::__construct($entityTypeManager);
    }

    protected function loginPath(): string
    {
        return $this->loginPathValue;
    }

    public function loginForm(Request $request): Response
    {
        if ($this->currentUser() !== null) {
            return new RedirectResponse($this->homePath);
        }

        return $this->render($this->loginTemplate, ['bare' => true]);
    }

    public function loginSubmit(Request $request): Response
    {
        $data = $this->json($request);
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $user = Auth::login($this->entityTypeManager, $email, $password);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Wrong email or password.'], 401);
        }

        return new JsonResponse(['ok' => true, 'redirect' => $this->homePath]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();

        return new RedirectResponse($this->loginPathValue);
    }

    public function setPasswordForm(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');
        $email = $this->tokens->emailForToken($token);

        return $this->render($this->setPasswordTemplate, [
            'bare' => true,
            'valid' => $email !== null,
            'email' => $email ?? '',
            'token' => $token,
        ]);
    }

    public function setPasswordSubmit(Request $request): Response
    {
        $data = $this->json($request);
        $token = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (strlen($password) < $this->minPasswordLength) {
            return new JsonResponse(['ok' => false, 'error' => sprintf('Password must be at least %d characters.', $this->minPasswordLength)], 422);
        }

        $email = $this->tokens->emailForToken($token);
        if ($email === null) {
            return new JsonResponse(['ok' => false, 'error' => 'This link is invalid or has already been used.'], 410);
        }

        $user = Auth::userByEmail($this->entityTypeManager, $email);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'No account found for this link.'], 404);
        }

        $this->entityTypeManager?->getStorage('user')->save($user->setRawPassword($password));
        $this->tokens->consume($token);

        return new JsonResponse(['ok' => true, 'redirect' => $this->loginPathValue]);
    }
}
