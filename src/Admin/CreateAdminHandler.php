<?php

declare(strict_types=1);

namespace Anokii\Admin;

use Anokii\Access\AbstractWorkspaceRoles;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * Canonical "create (or update) the admin account" logic for the single-admin
 * dashboard tier, shared by every install's `app:create-admin <email>
 * [--name=] [--password=]` command.
 *
 * The password is NEVER hardcoded: it is read from --password, or from the
 * install's configured environment variable (set from the vault / container
 * secrets), and stored only as a hash via User::setRawPassword(). The command
 * refuses to run without one (min 12 chars). The account is stamped with the
 * install's admin role (via {@see AbstractWorkspaceRoles}), so the dashboard gate
 * admits it. Idempotent: re-running updates the password and re-affirms the role.
 *
 * @api
 */
final class CreateAdminHandler
{
    /**
     * @param string $passwordEnvVar env var read when --password is absent
     *                               (e.g. "OIATC_ADMIN_PASSWORD"); never hardcoded
     * @param string $adminRoleId    the role id to stamp (e.g. AdminRoles::ROLE_ADMIN)
     * @param string $loginPath      where the success message tells the admin to sign in
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AbstractWorkspaceRoles $roles,
        private readonly string $passwordEnvVar,
        private readonly string $adminRoleId,
        private readonly string $loginPath,
    ) {}

    public function run(SymfonyCommandIO $io): int
    {
        $email = strtolower(trim((string) $io->argument('email')));
        if ($email === '' || !str_contains($email, '@')) {
            $io->error('Provide a valid email: app:create-admin <email> [--name=...] [--password=...]');

            return 1;
        }

        $password = (string) ($io->option('password') ?? '');
        if ($password === '') {
            $password = (string) (getenv($this->passwordEnvVar) ?: '');
        }
        if ($password === '') {
            $io->error(sprintf('No password given. Pass --password=... or set %s (from the vault). The password is never hardcoded.', $this->passwordEnvVar));

            return 1;
        }
        if (mb_strlen($password) < 12) {
            $io->error('Password too short: use at least 12 characters.');

            return 1;
        }

        $name = (string) ($io->option('name') ?? '');

        try {
            $storage = $this->entityTypeManager->getStorage('user');
            $user = $storage->loadByKey('mail', $email);

            if (!$user instanceof User) {
                $user = $storage->create(['name' => $name !== '' ? $name : $email, 'mail' => $email, 'status' => 1]);
                $created = true;
            } else {
                $created = false;
                if ($name !== '') {
                    $user = $user->setName($name);
                }
            }

            // Stamp the admin role (+ its permission) and the password hash. Each
            // setter returns a new instance; persist the final one.
            $user = $this->roles->apply($user, $this->adminRoleId);
            $user = $user->setRawPassword($password);
            $storage->save($user);
        } catch (\Throwable $e) {
            $io->error('Failed to create admin: ' . $e->getMessage());

            return 1;
        }

        $io->writeln(sprintf(
            '%s admin account %s (uid %s) with the %s role. Sign in at %s.',
            $created ? 'Created' : 'Updated',
            $email,
            (string) $user->id(),
            $this->adminRoleId,
            $this->loginPath,
        ));

        return 0;
    }
}
