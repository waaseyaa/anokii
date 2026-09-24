<?php

declare(strict_types=1);

namespace Anokii\Operator\Demo;

use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Module\OperatorModuleCatalog;
use Anokii\Operator\Module\OperatorModuleProviderInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Host-supplied, fictional data for one operator demo.
 *
 * A fixture is a PHP file that returns an array (see demo/README.md). Relative
 * paths inside it resolve against the fixture file's own directory, never
 * against the machine or the current working directory.
 */
final readonly class DemoFixture
{
    /** Asset extensions the demo serves, with their content types. */
    public const array ASSET_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    /** Context keys owned by the shell and the demo chrome; page context may not replace them. */
    private const array RESERVED_CONTEXT_KEYS = [
        'nav', 'tiles', 'nav_active', 'home_path', 'logout_path',
        'user_label', 'user_role', 'user_initials',
        'brand_title', 'brand_tag', 'brand_logo_src', 'brand_logo_alt', 'theme_href',
        'page_title', 'page_eyebrow', 'page_intro', 'page_blocks',
    ];

    /**
     * @param list<OperatorModule> $modules
     * @param array<string, array{template: ?string, title: ?string, eyebrow: ?string, intro: ?string, context: array<string, mixed>}> $pages keyed by module id
     * @param array<string, string> $assets URL path => absolute file path
     */
    private function __construct(
        public string $brandTitle,
        public string $brandTag,
        public string $brandLogoSrc,
        public string $brandLogoAlt,
        public string $themeHref,
        public string $operatorName,
        public string $operatorRole,
        public string $dashboardIntro,
        public array $modules,
        public array $pages,
        public ?string $templatesPath,
        public array $assets,
    ) {}

    public static function load(string $file): self
    {
        $path = realpath($file);
        if ($path === false || !is_file($path)) {
            throw new \InvalidArgumentException("Demo fixture file not found: {$file}");
        }
        $data = (static fn(string $fixture): mixed => require $fixture)($path);
        if (!is_array($data)) {
            throw new \InvalidArgumentException("Demo fixture {$path} must return an array.");
        }

        return self::fromArray($data, dirname($path));
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data, string $baseDir): self
    {
        self::rejectUnknownKeys($data, ['brand', 'theme_href', 'operator', 'dashboard_intro', 'modules', 'pages', 'templates', 'assets'], 'fixture');

        $assets = self::assets($data['assets'] ?? [], $baseDir);
        $brand = self::map($data['brand'] ?? null, 'brand');
        self::rejectUnknownKeys($brand, ['title', 'tag', 'logo_src', 'logo_alt'], 'brand');
        $operator = self::map($data['operator'] ?? null, 'operator');
        self::rejectUnknownKeys($operator, ['name', 'role'], 'operator');
        $modules = self::modules($data['modules'] ?? null);
        $templatesPath = isset($data['templates']) ? self::directory($data['templates'], $baseDir, 'templates') : null;
        $brandTitle = self::text($brand['title'] ?? null, 'brand.title');

        return new self(
            brandTitle: $brandTitle,
            brandTag: self::text($brand['tag'] ?? 'Operator workspace', 'brand.tag'),
            brandLogoSrc: self::assetReference($brand['logo_src'] ?? '', $assets, 'brand.logo_src'),
            brandLogoAlt: self::text($brand['logo_alt'] ?? $brandTitle, 'brand.logo_alt'),
            themeHref: self::assetReference($data['theme_href'] ?? '', $assets, 'theme_href'),
            operatorName: self::text($operator['name'] ?? null, 'operator.name'),
            operatorRole: self::text($operator['role'] ?? null, 'operator.role'),
            dashboardIntro: self::text($data['dashboard_intro'] ?? 'Choose the work you need to do.', 'dashboard_intro'),
            modules: $modules,
            pages: self::pages($data['pages'] ?? [], $modules, $templatesPath),
            templatesPath: $templatesPath,
            assets: $assets,
        );
    }

    /** @return list<OperatorModule> */
    private static function modules(mixed $value): array
    {
        $invalid = new \InvalidArgumentException('Demo fixture modules must be a non-empty list of OperatorModule values.');
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $invalid;
        }
        $fixtureModules = [];
        foreach ($value as $module) {
            if (!$module instanceof OperatorModule) {
                throw $invalid;
            }
            $fixtureModules[] = $module;
        }
        // Fixture modules pass through the production catalogue, so ids and
        // routes get exactly the validation a live workspace applies.
        $provider = new readonly class ($fixtureModules) implements OperatorModuleProviderInterface {
            /** @param list<OperatorModule> $modules */
            public function __construct(private array $modules) {}

            public function modules(AuthorizationPrincipalInterface $principal): iterable
            {
                yield from $this->modules;
            }
        };
        try {
            $modules = new OperatorModuleCatalog([$provider])
                ->forPrincipal(new AuthorizationPrincipal('demo-operator', true, [], [], 'anokii-operator-demo'));
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException('Demo fixture modules are invalid: ' . $e->getMessage(), previous: $e);
        }

        $hrefs = [];
        foreach ($modules as $module) {
            if (!str_starts_with($module->href, '/admin/anokii/') || strpbrk($module->href, '?#') !== false) {
                throw new \InvalidArgumentException("Demo module {$module->id} needs a path below /admin/anokii/ with no query or fragment.");
            }
            if (isset($hrefs[$module->href])) {
                throw new \InvalidArgumentException("Demo modules {$hrefs[$module->href]} and {$module->id} share the route {$module->href}.");
            }
            $hrefs[$module->href] = $module->id;
        }

        return $modules;
    }

    /**
     * @param list<OperatorModule> $modules
     *
     * @return array<string, array{template: ?string, title: ?string, eyebrow: ?string, intro: ?string, context: array<string, mixed>}>
     */
    private static function pages(mixed $value, array $modules, ?string $templatesPath): array
    {
        $ids = array_map(static fn(OperatorModule $module): string => $module->id, $modules);
        $pages = [];
        foreach (self::map($value, 'pages') as $id => $spec) {
            if (!in_array($id, $ids, true)) {
                throw new \InvalidArgumentException("Demo page {$id} has no matching module.");
            }
            $spec = self::map($spec, "pages.{$id}");
            self::rejectUnknownKeys($spec, ['template', 'title', 'eyebrow', 'intro', 'context'], "pages.{$id}");
            $template = isset($spec['template']) ? self::text($spec['template'], "pages.{$id}.template") : null;
            if ($template !== null) {
                if ($templatesPath === null) {
                    throw new \InvalidArgumentException("Demo page {$id} names a template, but the fixture has no templates directory.");
                }
                if (str_starts_with($template, '@') || in_array('..', explode('/', str_replace('\\', '/', $template)), true)
                    || !is_file($templatesPath . '/' . $template)) {
                    throw new \InvalidArgumentException("Demo page {$id} template {$template} must be a file inside the fixture's templates directory.");
                }
            }
            $context = self::map($spec['context'] ?? [], "pages.{$id}.context");
            $reserved = array_values(array_intersect(array_keys($context), self::RESERVED_CONTEXT_KEYS));
            if ($reserved !== []) {
                throw new \InvalidArgumentException("Demo page {$id} context may not set shell keys: " . implode(', ', $reserved) . '.');
            }
            $pages[$id] = [
                'template' => $template,
                'title' => isset($spec['title']) ? self::text($spec['title'], "pages.{$id}.title") : null,
                'eyebrow' => isset($spec['eyebrow']) ? self::text($spec['eyebrow'], "pages.{$id}.eyebrow") : null,
                'intro' => isset($spec['intro']) ? self::text($spec['intro'], "pages.{$id}.intro") : null,
                'context' => $context,
            ];
        }

        return $pages;
    }

    /** @return array<string, string> */
    private static function assets(mixed $value, string $baseDir): array
    {
        $assets = [];
        foreach (self::map($value, 'assets') as $url => $file) {
            if (preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#', $url) !== 1
                || in_array('..', explode('/', $url), true) || str_starts_with($url, '/admin/anokii')) {
                throw new \InvalidArgumentException("Demo asset path {$url} must be a plain local path outside /admin/anokii.");
            }
            if (str_starts_with($url, OperatorDemo::PACKAGE_ASSET_PREFIX)) {
                throw new \InvalidArgumentException("Demo asset path {$url} is reserved for the package's own demo assets.");
            }
            if (!isset(self::ASSET_TYPES[strtolower(pathinfo($url, PATHINFO_EXTENSION))])) {
                throw new \InvalidArgumentException("Demo asset {$url} has an unsupported type.");
            }
            $path = self::file($file, $baseDir, "assets.{$url}");
            $assets[$url] = $path;
        }

        return $assets;
    }

    /** @param array<string, string> $assets */
    private static function assetReference(mixed $value, array $assets, string $field): string
    {
        $reference = is_string($value) ? trim($value) : null;
        if ($reference === null || ($reference !== '' && !isset($assets[$reference]))) {
            throw new \InvalidArgumentException("Demo fixture {$field} must be empty or one of the fixture's asset paths.");
        }

        return $reference;
    }

    private static function directory(mixed $value, string $baseDir, string $field): string
    {
        $path = realpath(self::resolve(self::text($value, $field), $baseDir));
        if ($path === false || !is_dir($path)) {
            throw new \InvalidArgumentException("Demo fixture {$field} directory not found.");
        }

        return $path;
    }

    private static function file(mixed $value, string $baseDir, string $field): string
    {
        $path = realpath(self::resolve(self::text($value, $field), $baseDir));
        if ($path === false || !is_file($path)) {
            throw new \InvalidArgumentException("Demo fixture {$field} file not found.");
        }

        return $path;
    }

    private static function resolve(string $path, string $baseDir): string
    {
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $absolute ? $path : $baseDir . '/' . $path;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException("Demo fixture {$field} must be an array keyed by name.");
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException("Demo fixture {$field} must be an array keyed by name.");
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private static function rejectUnknownKeys(array $data, array $allowed, string $field): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Demo fixture {$field} has unknown keys: " . implode(', ', $unknown) . '.');
        }
    }

    private static function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Demo fixture {$field} must be a non-empty string.");
        }

        return trim($value);
    }
}
