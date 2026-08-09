<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use Anokii\Identity\Access\IdentityPillarAccessPolicy;
use Anokii\Identity\Controller\HostIdentityController;
use Anokii\Identity\Entity\Pillar;
use Anokii\Identity\IdentityServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Routing\WaaseyaaRouter;

final class IdentityPackageBoundaryTest extends TestCase
{
    public function testPackageProviderOwnsTheCanonicalIdentityEntity(): void
    {
        $provider = new IdentityServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->register();

        self::assertSame(['identity_pillar'], array_map(
            static fn($type): string => $type->id(),
            $provider->getEntityTypes(),
        ));
    }

    public function testReadOnlyHostSurfaceIsExplicitAndAuthenticated(): void
    {
        $disabled = new IdentityServiceProvider();
        $disabled->setKernelContext(dirname(__DIR__, 2), [], []);
        $disabledRouter = new WaaseyaaRouter();
        $disabled->routes($disabledRouter, $this->createStub(EntityTypeManager::class));
        self::assertNull($disabledRouter->getRouteCollection()->get('anokii.identity.host.identity'));

        $enabled = new IdentityServiceProvider();
        $enabled->setKernelContext(dirname(__DIR__, 2), [
            'anokii' => ['identity' => ['host_surface' => 'read_only']],
        ], []);
        $enabledRouter = new WaaseyaaRouter();
        $enabled->routes($enabledRouter, $this->createStub(EntityTypeManager::class));

        $match = $enabledRouter->match('/admin/anokii/identity');
        self::assertSame(HostIdentityController::class . '::index', $match['_controller'] ?? null);
        self::assertTrue(
            $enabledRouter->getRouteCollection()->get('anokii.identity.host.identity')?->getOption('_authenticated') ?? false,
        );
    }

    public function testRootDistributionConsumesTheSamePackageDomain(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $identity = json_decode((string) file_get_contents($root . '/packages/identity/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($identity);
        $rootRequire = $composer['require'] ?? null;
        $identityExtra = $identity['extra'] ?? null;
        self::assertIsArray($rootRequire);
        self::assertIsArray($identityExtra);
        $waaseyaa = $identityExtra['waaseyaa'] ?? null;
        self::assertIsArray($waaseyaa);
        $providers = $waaseyaa['providers'] ?? null;
        self::assertIsArray($providers);

        self::assertSame('dev-main', $rootRequire['waaseyaa/anokii-identity'] ?? null);
        self::assertSame(
            'dev-main',
            $composer['repositories'][0]['options']['versions']['waaseyaa/anokii-core'] ?? null,
        );
        self::assertSame(
            'dev-main',
            $composer['repositories'][1]['options']['versions']['waaseyaa/anokii-identity'] ?? null,
        );
        self::assertContains(IdentityServiceProvider::class, $providers);
        self::assertSame('migrations', $waaseyaa['migrations'] ?? null);
        self::assertFileDoesNotExist($root . '/src/Entity/Pillar.php');
        self::assertFileDoesNotExist($root . '/src/Access/IdentityPillarAccessPolicy.php');
    }

    public function testHostControllerRequiresAnImmutableAuthenticatedPrincipal(): void
    {
        $controller = $this->hostController([]);

        $response = $controller->index(Request::create('/admin/anokii/identity'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testHostControllerRendersOnlyAccessGrantedCanonicalPillarsPrivately(): void
    {
        $pillar = new Pillar([
            'community_id' => 'community-a',
            'classification_label' => 'nation-restricted',
            'pid' => 'purpose',
            'section' => 'foundations',
            'title' => '<Purpose>',
            'status' => 'defined',
        ]);
        $controller = $this->hostController([$pillar]);
        $request = Request::create('/admin/anokii/identity');
        $request->attributes->set('_account', new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: [],
            permissions: [],
            claimsGeneration: 'test-generation',
            communityId: 'community-a',
        ));

        $response = $controller->index($request);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('&lt;Purpose&gt;', $content);
        self::assertStringNotContainsString('<Purpose>', $content);
        self::assertStringContainsString('community-a', $content);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testHostControllerReturnsNoCrossCommunityContent(): void
    {
        $pillar = new Pillar([
            'community_id' => 'community-a',
            'classification_label' => 'nation-restricted',
            'pid' => 'secret',
            'title' => 'Other community secret',
        ]);
        $request = Request::create('/admin/anokii/identity');
        $request->attributes->set('_account', $this->principal('community-b'));

        $response = $this->hostController([$pillar])->index($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Other community secret', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testHostControllerFailsClosedAndPrivateWithoutAnActiveCommunity(): void
    {
        $request = Request::create('/admin/anokii/identity');
        $request->attributes->set('_account', $this->principal('community-a'));

        $response = $this->hostController([], activeCommunity: false)->index($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testIdentityWritesRequireBothCapabilityPermissionAndCommunityClaim(): void
    {
        $access = new EntityAccessHandler([new IdentityPillarAccessPolicy()]);

        self::assertTrue($access->checkCreateAccess('identity_pillar', '', $this->principal('community-a', ['edit identity']))->isAllowed());
        self::assertFalse($access->checkCreateAccess('identity_pillar', '', $this->principal('community-a'))->isAllowed());

        $withoutCommunity = new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: [],
            permissions: ['edit identity'],
            claimsGeneration: 'test-generation',
        );
        self::assertFalse($access->checkCreateAccess('identity_pillar', '', $withoutCommunity)->isAllowed());

        $pillar = new Pillar(['community_id' => 'community-a', 'pid' => 'purpose', 'title' => 'Purpose']);
        self::assertTrue($access->check($pillar, 'update', $this->principal('community-a', ['edit identity']))->isAllowed());
        self::assertFalse($access->check($pillar, 'update', $withoutCommunity)->isAllowed());
    }

    /** @param list<Pillar> $pillars */
    private function hostController(array $pillars, bool $activeCommunity = true): HostIdentityController
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findBy')->willReturn($pillars);
        $entities = $this->createStub(EntityTypeManager::class);
        $entities->method('getRepository')->willReturn($repository);
        $community = new CommunityContext();
        if ($activeCommunity) {
            $community->set('community-a');
        }

        return new HostIdentityController(
            $entities,
            new EntityAccessHandler([new IdentityPillarAccessPolicy()]),
            $community,
        );
    }

    /** @param list<string> $permissions */
    private function principal(string $communityId, array $permissions = []): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: [],
            permissions: $permissions,
            claimsGeneration: 'test-generation',
            communityId: $communityId,
        );
    }
}
