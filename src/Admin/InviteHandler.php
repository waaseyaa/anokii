<?php

declare(strict_types=1);

namespace Anokii\Admin;

use Anokii\Auth\SetupTokenRepository;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * Canonical "invite an account" logic for the workspace admin tier, shared by
 * every install's `anokii:invite <email> [--name=] [--base-url=]` command.
 *
 * Ensures an account exists for the email (created with NO usable password),
 * mints a one-time set-password token ({@see SetupTokenRepository}), and prints
 * the invite link. The holder opens the link and sets their own password
 * ({@see \Anokii\Dashboard\WorkspaceLoginController}). No password is ever
 * generated, stored, or printed here, only a one-time token link.
 *
 * @api
 */
final class InviteHandler
{
    /**
     * @param string $defaultBaseUrl  base URL when --base-url is absent (e.g. https://fnprocure.ca)
     * @param string $setPasswordPath the set-password route (e.g. /admin/anokii/set-password)
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly SetupTokenRepository $tokens,
        private readonly string $defaultBaseUrl,
        private readonly string $setPasswordPath,
    ) {}

    public function run(SymfonyCommandIO $io): int
    {
        $email = strtolower(trim((string) $io->argument('email')));
        if ($email === '' || !str_contains($email, '@')) {
            $io->error('Provide a valid email: anokii:invite <email> [--name=...] [--base-url=...]');

            return 1;
        }
        $name = (string) ($io->option('name') ?? '');
        $baseUrl = rtrim((string) ($io->option('base-url') ?? $this->defaultBaseUrl), '/');

        try {
            $storage = $this->entityTypeManager->getStorage('user');
            $user = $storage->loadByKey('mail', $email);

            if (!$user instanceof User) {
                $user = $storage->create(['name' => $name !== '' ? $name : $email, 'mail' => $email, 'status' => 1]);
                $storage->save($user);
                $io->writeln(sprintf('Created account for %s (uid %s).', $email, (string) $user->id()));
            } else {
                $io->writeln(sprintf('Account for %s already exists (uid %s).', $email, (string) $user->id()));
            }

            $token = $this->tokens->mint($email);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed: %s', $e->getMessage()));

            return 1;
        }

        $io->writeln('');
        $io->writeln('Set-password link (one-time, give this to the account holder):');
        $io->writeln(sprintf('%s%s?token=%s', $baseUrl, $this->setPasswordPath, $token));

        return 0;
    }
}
