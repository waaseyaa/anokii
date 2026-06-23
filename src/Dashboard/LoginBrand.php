<?php

declare(strict_types=1);

namespace Anokii\Dashboard;

/**
 * Presentation for the shared admin login page ({@see AdminLoginController}).
 *
 * The login flow is identical across installs; only the wording and the few
 * brand colours differ. An install passes its own LoginBrand so one package
 * login controller serves every tier from config, never a fork. Defaults are
 * neutral so an un-branded install still renders legibly.
 *
 * @api
 */
final class LoginBrand
{
    public function __construct(
        public readonly string $title = 'Admin sign in',
        public readonly string $subtitle = 'Administrator access.',
        public readonly string $accent = '#4f2fb0',
        public readonly string $accentDeep = '#38217f',
        public readonly string $link = '#c41d8f',
        public readonly string $backHref = '/',
        public readonly string $backLabel = 'Back to the site',
    ) {}
}
