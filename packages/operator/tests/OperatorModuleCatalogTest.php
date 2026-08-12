<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Http\OperatorResponsePolicy;
use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Module\OperatorModuleCatalog;
use Anokii\Operator\Module\OperatorModuleProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;

require_once __DIR__.'/../src/Module/OperatorModule.php';
require_once __DIR__.'/../src/Module/OperatorModuleProviderInterface.php';
require_once __DIR__.'/../src/Module/OperatorModuleCatalog.php';
require_once __DIR__.'/../src/Http/OperatorResponsePolicy.php';

final class OperatorModuleCatalogTest extends TestCase
{
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

        $modules = (new OperatorModuleCatalog([$provider]))->forPrincipal($principal);

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
        (new OperatorModuleCatalog([$provider]))->forPrincipal($principal);
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
}
