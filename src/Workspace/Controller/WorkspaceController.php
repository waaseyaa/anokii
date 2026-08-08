<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Access\AccountBoundary;
use Anokii\Dashboard\DashboardGate;
use Anokii\Support\Values;
use Anokii\Workspace\WorkspaceShell;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * The Anokii login-gated workspace shell: dashboard home, user settings, and the
 * coming-soon placeholder for preview modules. The login / logout / one-time
 * set-password surface comes from the shared {@see \Anokii\Dashboard\WorkspaceLoginController},
 * wired in {@see \Anokii\Provider\WorkspaceServiceProvider}; this controller owns
 * only the authed workspace pages.
 *
 * Extends {@see DashboardGate}: the login-gated split, the session helpers
 * (currentUser), and the Twig-render / JSON-decode helpers all come from the base.
 * Unauthenticated page requests redirect to /admin/anokii/login; JSON actions 401.
 *
 * @api
 */
final class WorkspaceController extends DashboardGate
{
    public function __construct(
        ?EntityTypeManager $entityTypeManager,
        ?\Waaseyaa\Access\User\UserInternalFieldReaderInterface $internalFieldReader = null,
        private readonly ?AccountBoundary $accounts = null,
    ) {
        parent::__construct($entityTypeManager, $internalFieldReader);
    }

    /**
     * The audited account boundary. Absent only when the controller was mounted
     * without it, which is a wiring fault and must fail loudly rather than
     * degrade to an unaudited read.
     */
    private function accounts(): AccountBoundary
    {
        return $this->accounts ?? throw new \LogicException(sprintf(
            '%s was mounted without an %s; the workspace shell cannot resolve the signed-in '
            . 'account without audited read authority. Pass the boundary to the constructor.',
            static::class,
            AccountBoundary::class,
        ));
    }

    protected function loginPath(): string
    {
        return '/admin/anokii/login';
    }

    public function dashboard(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return new RedirectResponse($this->loginPath());
        }

        return $this->render(
            'anokii/home.html.twig',
            WorkspaceShell::context($this->accounts()->identity($user), $user->id(), 'dashboard'),
        );
    }

    public function comingSoon(Request $request, string $module): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return new RedirectResponse($this->loginPath());
        }
        $m = WorkspaceShell::find($module);
        if ($m === null || ($m['live'] ?? false) === true) {
            return new RedirectResponse('/admin/anokii');
        }

        return $this->render(
            'anokii/coming-soon.html.twig',
            WorkspaceShell::context($this->accounts()->identity($user), $user->id(), $module) + ['module' => $m],
        );
    }

    public function settings(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return new RedirectResponse($this->loginPath());
        }

        // `name` is Protected and `mail` is Internal on the sealed User; both
        // come from the one audited session-identity read, never off the entity.
        $identity = $this->accounts()->identity($user);

        return $this->render('anokii/settings.html.twig', WorkspaceShell::context($identity, $user->id(), 'settings') + [
            'profile_name' => $identity->name,
            'profile_email' => $identity->mail,
        ]);
    }

    public function settingsSave(Request $request): Response
    {
        $denied = $this->requireAction();
        if ($denied !== null) {
            return $denied;
        }
        $user = $this->currentUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $data = $this->json($request);

        $name = Values::trimmed($data['name'] ?? null);
        $email = Values::trimmed($data['email'] ?? null);
        $current = Values::str($data['current_password'] ?? null);
        $new = Values::str($data['new_password'] ?? null);

        $updated = $user;
        $identity = $this->accounts()->identity($user);
        if ($name !== '') {
            $updated = $updated->setName($name);
        }
        if ($email !== '' && strtolower($email) !== strtolower(trim($identity->mail))) {
            // Changing a login identifier without a verified-email workflow can
            // orphan an account or transfer it to an unverified address. Keep
            // the current audited address immutable until that workflow exists.
            return new JsonResponse([
                'ok' => false,
                'error' => 'Email changes require administrator verification.',
            ], 422);
        }

        if ($new !== '') {
            if (strlen($new) < 10) {
                return new JsonResponse(['ok' => false, 'error' => 'New password must be at least 10 characters.'], 422);
            }
            if ($this->internalFields === null) {
                throw new \LogicException(sprintf(
                    '%s was mounted without a UserInternalFieldReaderInterface; the password-change '
                    . 'flow cannot verify the current password. Pass the resolved reader to the constructor.',
                    static::class,
                ));
            }
            // Credential verification via the framework's audited authority —
            // the sealed entity can no longer answer checkPassword() itself.
            if (!new \Waaseyaa\Auth\AuthManager($this->internalFields)->authenticate($user, $current)) {
                return new JsonResponse(['ok' => false, 'error' => 'Current password is incorrect.'], 422);
            }
            $updated = $updated->setRawPassword($new);
        }

        $this->entityTypeManager?->getRepository('user')->save($updated);

        return new JsonResponse(['ok' => true]);
    }
}
