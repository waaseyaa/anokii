<?php

declare(strict_types=1);

namespace Anokii\Support;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSessionSnapshot;
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
     * session or the account no longer exists. A missing or deleted account
     * resolves to null without throwing. An infrastructure failure while loading
     * (storage misconfigured, database unavailable) propagates rather than being
     * silently converted to "no account", so a real fault surfaces instead of
     * hiding as a routine logged-out state.
     *
     * @api
     */
    public static function currentUser(?EntityTypeManager $entityTypeManager): ?User
    {
        if ($entityTypeManager === null) {
            return null;
        }

        $uid = Values::str($_SESSION['waaseyaa_uid'] ?? null);
        if ($uid === '') {
            return null;
        }

        // find() returns null for a missing/deleted account; it only throws on a
        // genuine storage fault, which must not be swallowed here. Catching it
        // is what let the alpha.254 storage regression masquerade as a routine
        // logged-out state for a month.
        $user = $entityTypeManager->getRepository('user')->find($uid);

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
        UserInternalFieldReaderInterface $internalFields,
    ): ?User {
        $user = self::userByEmail($entityTypeManager, $email);
        if ($user === null) {
            return null;
        }

        // Credential verification goes through the framework's audited
        // internal-field authority (framework ≥ alpha.269 seals the password
        // hash as Internal; AuthManager owns the capability-scoped read).
        $auth = new AuthManager($internalFields);
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
        // Logout only tears the session down — it never reads user internals,
        // so the AuthManager is constructed with a loud null-object reader
        // rather than real read authority.
        new AuthManager(new UnusedInternalFieldReader())->logout();
    }

    /**
     * Resolve a user by email address, or null when no account matches. Email is
     * matched case-insensitively against the 'mail' entity key. An
     * infrastructure failure while looking up propagates rather than being
     * swallowed: on the login path a database fault must surface as an error, it
     * must never present to the visitor as "wrong email or password".
     *
     * @api
     */
    public static function userByEmail(?EntityTypeManager $entityTypeManager, string $email): ?User
    {
        $email = strtolower(trim($email));
        if ($email === '' || $entityTypeManager === null) {
            return null;
        }

        // No match returns an empty array (→ null); an exception is a real
        // storage fault and is deliberately left to propagate, so a broken
        // database on the login path becomes a 500, not a false "wrong
        // password". This is the core defect the alpha.254 postmortem named.
        $matches = $entityTypeManager->getRepository('user')->findBy(['mail' => $email], null, 1);
        $user = $matches[0] ?? null;

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
     * A friendly display label for a signed-in account: the name when set,
     * otherwise the email address, otherwise the account id.
     *
     * Takes the AUDITED identity snapshot rather than the User entity, so this
     * method cannot reach a sealed field: `name` is Protected and `mail` is
     * Internal on framework ≥ alpha.269, and reading either off the entity threw
     * (MissingFieldReadContext / FieldReadDenied) — a 500 on every shell page.
     * {@see \Anokii\Access\AccountBoundary} owns the one audited read that
     * produces the snapshot; this is pure formatting over already-released
     * values.
     *
     * The last resort is the account id, not a fabricated name: an account the
     * shell genuinely cannot name is labelled truthfully rather than presented
     * as someone else, and attribution written to a revision stays traceable.
     *
     * @api
     */
    public static function label(UserSessionSnapshot $identity, int|string $accountId): string
    {
        $name = trim($identity->name);
        if ($name !== '') {
            return $name;
        }

        $mail = trim($identity->mail);

        return $mail !== '' ? $mail : 'Account #' . $accountId;
    }
}
