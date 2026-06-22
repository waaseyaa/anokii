<?php

declare(strict_types=1);

namespace Anokii\Admin;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Registers the Anokii package template directory on an instance's Twig
 * environment, so every install resolves the shared shell and admin templates
 * (anokii/_shell.html.twig, anokii/admin/*.html.twig) from one place instead of
 * forking them.
 *
 * The instance's own templates keep priority: this APPENDS the package path, so a
 * template the instance also defines under the same logical name still wins.
 *
 * @api
 */
final class AdminTemplates
{
    /** Absolute path to the package's templates/ directory. */
    public static function path(): string
    {
        return \dirname(__DIR__, 2) . '/templates';
    }

    /**
     * Append the package templates path to a FilesystemLoader-backed Twig
     * environment. No-op (without error) if the loader is not a FilesystemLoader
     * or the path is already registered.
     *
     * @api
     */
    public static function register(Environment $twig): void
    {
        $loader = $twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return;
        }
        $path = self::path();
        if (!is_dir($path) || in_array($path, $loader->getPaths(), true)) {
            return;
        }
        $loader->addPath($path);
    }
}
