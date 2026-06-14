<?php

declare(strict_types=1);

namespace Anokii\Support;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * Session-backed current-account helper for the Anokii dashboard shell.
 *
 * Every Anokii instance re-derives the same four operations on the framework
 * session: read the signed-in {@see User} from $_SESSION['waaseyaa_uid'],
 * log a user in, log a user out, and gate a request behind a real account.
 * This is the extracted common core; instances consume it directly rather than
 * copying it.
 *
 * Why $_SESSION directly and not the request _account attribute: the dashboard
 * gate must NOT be satisfied by the framework dev-fallback account
 * (DevAdminAccount, id PHP_INT_MAX) that SessionMiddleware injects in
 * development. Reading the raw session uid (the value AuthManager::login()
 * writes) means only a genuine login opens the gate, in every environment.
 *
 * This helper builds strictly on the framework: AuthManager owns the session
 * write/clear, EntityTypeManager owns user loading. Anokii adds no parallel
 * session machinery.
 *
 * @api
 */
final class Auth
{
    /**
     * The signed-in user for this request, or null when there is no valid
     * session. Returns null (never throws) on any load failure so callers can
     * treat "no account" and "broken account" identically at the gate.
     *
     * @api
     */
    public static function currentUser(?EntityTypeManager $entityTypeManager): ?User
    {
        if ($entityTypeManager === null) {
            return null;
        }

        $uid = $_SESSION['waaseyaa_uid'] ?? null;
        if ($uid === null || $uid === '') {
            return null;
        }

        try {
            $user = $entityTypeManager->getStorage('user')->load((int) $uid);
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * Whether a valid session account exists for this request.
     *
     * @api
     */
    public static function check(?EntityTypeManager $entityTypeManager): bool
    {
        return self::currentUser($entityTypeManager) !== null;
    }

    /**
     * Validate credentials and open a session. Returns the signed-in user on
     * success, or null when the email is unknown or the password is wrong.
     *
     * Delegates credential checking and the session write to the framework's
     * AuthManager; this method only resolves the account by email first.
     *
     * @api
     */
    public static function login(
        ?EntityTypeManager $entityTypeManager,
        string $email,
        string $password,
    ): ?User {
        $user = self::userByEmail($entityTypeManager, $email);
        if ($user === null) {
            return null;
        }

        $auth = new AuthManager();
        if (!$auth->authenticate($user, $password)) {
            return null;
        }

        $auth->login($user);

        return $user;
    }

    /**
     * Clear the session. Wraps AuthManager::logout() so instances depend on a
     * single Anokii entry point.
     *
     * @api
     */
    public static function logout(): void
    {
        (new AuthManager())->logout();
    }

    /**
     * Resolve a user by email address, or null when unknown or unloadable.
     * Email is matched case-insensitively against the 'mail' entity key.
     *
     * @api
     */
    public static function userByEmail(?EntityTypeManager $entityTypeManager, string $email): ?User
    {
        $email = strtolower(trim($email));
        if ($email === '' || $entityTypeManager === null) {
            return null;
        }

        try {
            $user = $entityTypeManager->getStorage('user')->loadByKey('mail', $email);
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * Gate a request behind a real account. Returns null when the request may
     * proceed, or a RedirectResponse to the app-owned login path when it may
     * not. The login path is supplied by the caller so the redirect target
     * stays app-owned, never a framework default.
     *
     * @api
     */
    public static function requireAccountOrRedirect(
        ?EntityTypeManager $entityTypeManager,
        string $loginPath,
    ): ?RedirectResponse {
        return self::currentUser($entityTypeManager) === null
            ? new RedirectResponse($loginPath)
            : null;
    }

    /**
     * A friendly display label for a signed-in user: the name when set,
     * otherwise the email address.
     *
     * @api
     */
    public static function label(User $user): string
    {
        $name = trim($user->getName());

        return $name !== '' ? $name : $user->getEmail();
    }
}
