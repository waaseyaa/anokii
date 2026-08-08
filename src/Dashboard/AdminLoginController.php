<?php

declare(strict_types=1);

namespace Anokii\Dashboard;

use Anokii\Access\WorkspacePermissions;
use Anokii\Auth\LoginThrottle;
use Anokii\Support\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Middleware\CsrfMiddleware;

/**
 * Canonical admin login flow for Anokii installs: a server-rendered sign-in form
 * (form POST), credential check via {@see Auth}, an optional required permission,
 * CSRF, an open-redirect-safe ?next=, and sign-out. One implementation, branded
 * and pathed by config, so rhtcircle and oiatc (and any single-admin tier) share
 * it instead of each carrying a copy.
 *
 * Routes are registered allowAll() at the framework layer; this controller owns
 * the login surface, while the dashboard pages enforce {@see DashboardGate::requirePermission()}.
 *
 * @api
 */
final class AdminLoginController extends DashboardGate
{
    /**
     * @param string  $loginPath          this login form's own path (form action + redirect target)
     * @param string  $homePath           where a successful / already-authenticated sign-in lands
     * @param ?string $requiredPermission permission an account must hold to sign in here
     *                                     (null = any authenticated account); the dashboard
     *                                     pages gate on the same permission
     * @param string  $pathPrefix         the ?next= safelist prefix (e.g. '/admin'); only a
     *                                     same-origin path under it is accepted
     */
    public function __construct(
        ?EntityTypeManager $entityTypeManager,
        private readonly string $loginPath,
        private readonly string $homePath,
        private readonly ?string $requiredPermission,
        private readonly LoginBrand $brand,
        UserInternalFieldReaderInterface $internalFieldReader,
        private readonly LoginThrottle $loginThrottle,
        private readonly string $pathPrefix = '/admin',
    ) {
        parent::__construct($entityTypeManager, $internalFieldReader);
    }

    protected function loginPath(): string
    {
        return $this->loginPath;
    }

    public function loginForm(Request $request): Response
    {
        $already = $this->redirectIfAuthenticated($this->safeNext($request));
        if ($already !== null) {
            return $already;
        }

        return $this->loginPage($this->safeNext($request), $request->query->get('error') !== null);
    }

    public function loginSubmit(Request $request): Response
    {
        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $next = $this->safeNext($request);

        if ($this->loginThrottle->isBlocked($request, $email)) {
            return new Response('Too many sign-in attempts. Try again later.', 429, ['Retry-After' => '900']);
        }

        \assert($this->internalFields !== null); // constructor requires it
        $user = Auth::login($this->entityTypeManager, $email, $password, $this->internalFields);
        $permitted = $user !== null && (
            $this->requiredPermission === null
            || WorkspacePermissions::allows($this->internalFields->maintenanceAuthorization($user), $this->requiredPermission)
        );
        if (!$permitted) {
            $this->loginThrottle->recordFailure($request, $email);
            if ($user !== null) {
                Auth::logout();
            }

            return $this->loginPage($next, true);
        }

        $this->loginThrottle->recordSuccess($email);

        CsrfMiddleware::regenerate();

        return new RedirectResponse($next);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        return new RedirectResponse($this->loginPath);
    }

    /**
     * A same-origin redirect target after login: only an app-local path under the
     * configured prefix is accepted (and never the login path itself), defaulting
     * to the dashboard home. Prevents open-redirect via ?next=.
     */
    private function safeNext(Request $request): string
    {
        $next = (string) ($request->request->get('next') ?? $request->query->get('next', ''));
        $underPrefix = $next === $this->pathPrefix || str_starts_with($next, rtrim($this->pathPrefix, '/') . '/');
        $isLogin = $next === $this->loginPath || str_starts_with($next, rtrim($this->loginPath, '/') . '/');
        if ($next !== '' && $underPrefix && !$isLogin) {
            return $next;
        }

        return $this->homePath;
    }

    private function loginPage(string $next, bool $error): Response
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $token = CsrfMiddleware::token();
        $b = $this->brand;
        $err = $error
            ? '<p class="err">Incorrect email or password, or this account is not authorized.</p>'
            : '';
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$e($b->title)}</title>
            <style>
              :root { --bg:#fbfaff; --surface:#fff; --ink:#221d33; --ink-3:#6f6688; --rule:#e4def2; --accent:{$e($b->accent)}; --accent-deep:{$e($b->accentDeep)}; --link:{$e($b->link)}; --sans:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
              * { box-sizing:border-box; }
              body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font-family:var(--sans); }
              .card { width:100%; max-width:360px; background:var(--surface); border:1px solid var(--rule); border-radius:14px; padding:28px 26px; margin:24px; }
              h1 { font-size:19px; margin:0 0 4px; color:var(--accent-deep); }
              p.sub { margin:0 0 20px; color:var(--ink-3); font-size:13.5px; }
              label { display:block; font-size:13px; font-weight:600; margin:14px 0 5px; }
              input { width:100%; padding:11px 13px; font-size:15px; border:1px solid #d6cdea; border-radius:9px; background:#fff; color:var(--ink); }
              input:focus { outline:3px solid #6a3cd9; outline-offset:1px; border-color:var(--accent); }
              button { width:100%; margin-top:20px; padding:12px; font-size:15px; font-weight:600; color:#fff; background:var(--accent); border:none; border-radius:999px; cursor:pointer; }
              button:hover { background:var(--accent-deep); }
              .err { background:#fbe9f3; color:#98146d; border-radius:8px; padding:9px 12px; font-size:13.5px; margin:0 0 6px; }
              a { color:var(--link); font-size:13px; }
            </style>
            </head>
            <body>
              <form class="card" method="post" action="{$e($this->loginPath)}">
                <h1>Sign in</h1>
                <p class="sub">{$e($b->subtitle)}</p>
                {$err}
                <input type="hidden" name="_csrf_token" value="{$e($token)}">
                <input type="hidden" name="next" value="{$e($next)}">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" autofocus required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="submit">Sign in</button>
                <p style="margin:18px 0 0"><a href="{$e($b->backHref)}">{$e($b->backLabel)}</a></p>
              </form>
            </body>
            </html>
            HTML;

        return new Response($html, $error ? 401 : 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
