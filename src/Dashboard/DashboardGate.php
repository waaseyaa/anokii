<?php

declare(strict_types=1);

namespace Anokii\Dashboard;

use Anokii\Support\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\User\User;

/**
 * Abstract base for Anokii dashboard controllers.
 *
 * Captures the public-open / dashboard-login-gated split every Anokii instance
 * re-implements by hand:
 *
 *   - Dashboard routes are registered allowAll() at the framework layer (the
 *     framework's AccessChecker does NOT gate them), so the controller enforces
 *     the session itself. This keeps the redirect target app-owned instead of
 *     the framework's hard-coded /login.
 *   - An unauthenticated PAGE request is redirected to the app's login path
 *     ({@see loginPath()}); an unauthenticated JSON/action request gets a 401
 *     JSON body instead of an HTML redirect, because an XHR cannot follow an
 *     HTML redirect meaningfully.
 *
 * A concrete dashboard controller subclasses this, declares its login path, and
 * calls {@see requirePage()} / {@see requireAction()} at the top of each
 * handler, then renders with {@see render()}.
 *
 * Built strictly on the framework: account resolution via {@see Auth} (which
 * reads the framework session), Twig via SsrServiceProvider.
 *
 * @api
 */
abstract class DashboardGate
{
    public function __construct(
        protected readonly ?EntityTypeManager $entityTypeManager = null,
    ) {}

    /**
     * The app-owned path an unauthenticated PAGE request is redirected to.
     * Instances override this (for example '/dashboard/login' or
     * '/anokii/login'); it is never a framework default.
     *
     * @api
     */
    abstract protected function loginPath(): string;

    /**
     * Gate a PAGE handler. Returns null when the request may proceed (an
     * account exists), or a RedirectResponse to {@see loginPath()} when it may
     * not.
     *
     * Usage:
     *   $gate = $this->requirePage();
     *   if ($gate !== null) { return $gate; }
     *   $user = $this->currentUser(); // guaranteed non-null here
     *
     * @api
     */
    protected function requirePage(): ?RedirectResponse
    {
        return Auth::requireAccountOrRedirect($this->entityTypeManager, $this->loginPath());
    }

    /**
     * Gate a JSON/action handler. Returns null when the request may proceed, or
     * a 401 JSON response when no account is present. The shape matches the
     * instances' action responses: { ok: false, error: <message> }.
     *
     * @api
     */
    protected function requireAction(string $message = 'Not signed in.'): ?JsonResponse
    {
        if (Auth::check($this->entityTypeManager)) {
            return null;
        }

        return new JsonResponse(['ok' => false, 'error' => $message], 401);
    }

    /**
     * Gate a PAGE handler behind a real account AND a permission. Returns null
     * when the request may proceed; a redirect to {@see loginPath()} (carrying a
     * ?next= back to the requested path) when anonymous; a 403 page when signed in
     * without the permission. This is the permission-gated variant of
     * {@see requirePage()} for the single-admin dashboard tier.
     *
     * @api
     */
    protected function requirePermission(Request $request, string $permission): ?Response
    {
        $redirect = Auth::requireAccountOrRedirect(
            $this->entityTypeManager,
            $this->loginPath() . '?next=' . rawurlencode($request->getPathInfo()),
        );
        if ($redirect !== null) {
            return $redirect;
        }

        $user = $this->currentUser();
        if ($user === null || !$user->hasPermission($permission)) {
            return $this->forbiddenResponse();
        }

        return null;
    }

    /**
     * The 403 shown to a signed-in account that lacks the required permission.
     * Neutral by default; an instance may override to brand it.
     *
     * @api
     */
    protected function forbiddenResponse(): Response
    {
        $html = <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">
            <title>Not authorized</title></head>
            <body style="font-family:system-ui,sans-serif;max-width:32rem;margin:12vh auto;padding:0 1.5rem;color:#221d33">
            <h1 style="color:#38217f">Not authorized</h1>
            <p>You are signed in, but this account does not have access to this area.</p>
            <p><a href="/">Back to the site</a></p>
            </body></html>
            HTML;

        return new Response($html, 403, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The signed-in user for this request, or null. After a null check from
     * {@see requirePage()} / {@see requireAction()} this is guaranteed non-null.
     *
     * @api
     */
    protected function currentUser(): ?User
    {
        return Auth::currentUser($this->entityTypeManager);
    }

    /**
     * Redirect an ALREADY-authenticated visitor away from the login form to the
     * given dashboard home path. Returns null when the visitor is anonymous and
     * should see the login form.
     *
     * @api
     */
    protected function redirectIfAuthenticated(string $homePath): ?RedirectResponse
    {
        return Auth::check($this->entityTypeManager)
            ? new RedirectResponse($homePath)
            : null;
    }

    /**
     * Decode a JSON request body to an associative array, tolerating an empty
     * or non-object body (returns []).
     *
     * @return array<string, mixed>
     *
     * @api
     */
    protected function json(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Render a Twig template to an HTML Response. Returns a 500 when Twig is not
     * initialised, so a misconfigured SSR environment fails loudly rather than
     * blank.
     *
     * @param array<string, mixed> $context
     *
     * @api
     */
    protected function render(string $template, array $context = []): Response
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Dashboard unavailable: Twig is not initialised.', 500);
        }

        return new Response(
            $twig->render($template, $context),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
