<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Http\OperatorResponsePolicy;
use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Module\OperatorModuleCatalog;
use Anokii\Operator\Module\OperatorModuleProviderInterface;
use Anokii\Operator\Shell\OperatorShell;
use Anokii\Operator\Template\OperatorTemplates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;

require_once __DIR__ . '/../src/Module/OperatorModule.php';
require_once __DIR__ . '/../src/Module/OperatorModuleProviderInterface.php';
require_once __DIR__ . '/../src/Module/OperatorModuleCatalog.php';
require_once __DIR__ . '/../src/Http/OperatorResponsePolicy.php';
require_once __DIR__ . '/../src/Shell/OperatorShell.php';
require_once __DIR__ . '/../src/Template/OperatorTemplates.php';

final class OperatorModuleCatalogTest extends TestCase
{
    #[Test]
    public function packageDeclaresTheMultibyteRuntimeUsedByOperatorInitials(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        $requirements = $composer['require'] ?? null;
        self::assertIsArray($requirements);
        self::assertSame('*', $requirements['ext-mbstring'] ?? null);
    }

    #[Test]
    public function hostModulesAreResolvedForTheExactPrincipalInProviderOrder(): void
    {
        $principal = new AuthorizationPrincipal(7, true, ['communications'], ['access sfn authoring surface'], 'operator-test');
        $provider = new class implements OperatorModuleProviderInterface {
            public function modules(AuthorizationPrincipalInterface $principal): iterable
            {
                if ($principal->hasPermission('access sfn authoring surface')) {
                    yield new OperatorModule('website', 'Website', 'Daily work', '/admin/anokii/website', 'Manage public site content.');
                }
                if ($principal->hasPermission('sfn_manage_members')) {
                    yield new OperatorModule('members', 'Members', 'Daily work', '/admin/anokii/members', 'Manage members portal access.');
                }
            }
        };

        $modules = new OperatorModuleCatalog([$provider])->forPrincipal($principal);

        self::assertSame(['website'], array_map(static fn(OperatorModule $module): string => $module->id, $modules));
    }

    #[Test]
    public function duplicateModuleIdsFailClosed(): void
    {
        $principal = new AuthorizationPrincipal(7, true, ['administrator'], [], 'operator-test');
        $provider = new class implements OperatorModuleProviderInterface {
            public function modules(AuthorizationPrincipalInterface $principal): iterable
            {
                yield new OperatorModule('website', 'Website', 'Daily work', '/admin/anokii/website', 'First definition.');
                yield new OperatorModule('website', 'Website again', 'Daily work', '/admin/anokii/website-again', 'Conflicting definition.');
            }
        };

        $this->expectException(\LogicException::class);
        new OperatorModuleCatalog([$provider])->forPrincipal($principal);
    }

    #[Test]
    public function invalidModuleIdentityOrExternalRouteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperatorModule('Website Admin', 'Website', 'Daily work', 'https://example.invalid/admin', 'Invalid module.');
    }

    #[Test]
    public function operatorResponsesArePrivateAndUnindexable(): void
    {
        $response = OperatorResponsePolicy::apply(new Response('operator'));

        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    #[Test]
    public function hostBrandingOverridesDefaultsWithoutReplacingAuthorizedModules(): void
    {
        $modules = [new OperatorModule('website', 'Website', 'Daily work', '/admin/anokii/website', 'Manage the public website.')];
        $context = OperatorShell::context($modules, '', 'Matthew Owl', 'Communications Officer', [
            'brand_title' => 'Sheguiandah First Nation',
            'brand_tag' => 'Anokii workspace',
            'brand_logo_src' => '/media/sfn-logo.png',
            'brand_logo_alt' => 'Sheguiandah First Nation',
            'theme_href' => '/assets/sfn-operator.css',
            'nav' => [],
            'tiles' => [],
        ]);

        /** @var list<array{id: string}> $nav */
        $nav = $context['nav'];
        /** @var list<array{id: string}> $tiles */
        $tiles = $context['tiles'];
        self::assertSame('Sheguiandah First Nation', $context['brand_title']);
        self::assertSame(['website'], array_column($nav, 'id'));
        self::assertSame(['website'], array_column($tiles, 'id'));

        $twig = new Environment(new FilesystemLoader());
        OperatorTemplates::register($twig);
        $html = $twig->render('@anokii_operator/dashboard.html.twig', $context);
        self::assertStringContainsString('Sheguiandah First Nation', $html);
        self::assertStringContainsString('/media/sfn-logo.png', $html);
        self::assertStringContainsString('/assets/sfn-operator.css', $html);
        self::assertStringContainsString('/admin/anokii/website', $html);
        self::assertStringContainsString('Open workspace navigation', $html);
        self::assertStringContainsString('grid-template-rows:auto minmax(0,1fr)', $html);
    }

    #[Test]
    public function operatorInitialsPreserveMultibyteNames(): void
    {
        $context = OperatorShell::context([], '', 'Élodie Métis', 'Operator');

        self::assertSame('ÉM', $context['user_initials']);
    }
}
