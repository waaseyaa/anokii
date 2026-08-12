<?php

declare(strict_types=1);

namespace Anokii\Operator\Template;

use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/** Registers package templates under the stable @anokii_operator namespace. */
final class OperatorTemplates
{
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/templates';
    }

    public static function register(Environment $twig): void
    {
        $path = self::path();
        if (!is_dir($path)) {
            throw new \RuntimeException('The Anokii operator template directory is missing.');
        }
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader) {
            if (!in_array($path, $loader->getPaths('anokii_operator'), true)) {
                $loader->addPath($path, 'anokii_operator');
            }

            return;
        }
        if ($loader instanceof ChainLoader) {
            $packageLoader = new FilesystemLoader();
            $packageLoader->addPath($path, 'anokii_operator');
            $loader->addLoader($packageLoader);

            return;
        }

        throw new \RuntimeException('Anokii operator templates require a filesystem-capable Twig loader.');
    }
}
