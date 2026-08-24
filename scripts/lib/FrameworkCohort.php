<?php

declare(strict_types=1);

namespace Anokii\CandidateCi;

use Composer\InstalledVersions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * Governed Framework cohort operations for Anokii CI.
 *
 * Two lanes use this class and nothing else does:
 *
 *  - the exact-SHA candidate lane, which proves a waaseyaa/framework commit
 *    against a disposable Anokii clone without ever mutating the canonical
 *    composer.json or composer.lock, and
 *  - the released-adoption lane, which moves only the released Framework
 *    cohort forward while preserving Anokii's own dev-main split identities.
 *
 * Anokii is itself a Framework-side package repository. waaseyaa/anokii-core,
 * waaseyaa/anokii-identity, and waaseyaa/anokii-operator are path packages
 * resolved at dev-main from packages/core, packages/identity, and
 * packages/operator. They are never Framework candidates and never adoption
 * targets; both lanes refuse any movement in them.
 */
final class FrameworkCohort
{
    public const CANDIDATE_SCHEMA = 'anokii.framework-candidate.v1';

    public const ADOPTION_SCHEMA = 'anokii.framework-release-adoption.v1';

    public const TRUST_MERGED_MAIN = 'merged-main';

    public const TRUST_REVIEW_CANDIDATE = 'review-candidate';

    public const FRAMEWORK_REPOSITORY = 'https://github.com/waaseyaa/framework';

    public const DEFAULT_MAIN_REF = 'refs/remotes/origin/main';

    public const MANIFEST_NAME = 'manifest.json';

    public const ANOKII_PACKAGE_PREFIX = 'waaseyaa/anokii-';

    public const PROCESS_TIMEOUT_SECONDS = 1800;

    public const GIT_TIMEOUT_SECONDS = 60;

    public const PROCESS_OUTPUT_BOUND = 262144;

    public const PROCESS_DIAGNOSTIC_TAIL = 16384;

    /**
     * Anokii's own split packages. They stay at dev-main from the local path
     * repositories in every lane.
     *
     * @var list<string>
     */
    public const ANOKII_DEV_MAIN_PACKAGES = [
        'waaseyaa/anokii-core',
        'waaseyaa/anokii-identity',
        'waaseyaa/anokii-operator',
    ];

    /**
     * The only path repositories Anokii is allowed to declare. Any other path
     * repository in the root manifest is refused before a lane does any work.
     *
     * @var list<string>
     */
    public const ANOKII_PATH_REPOSITORIES = [
        'packages/core',
        'packages/identity',
        'packages/operator',
    ];

    /**
     * Framework packages that Anokii Identity and Access sit directly on top
     * of. The candidate lane refuses to report success unless a real class from
     * each of these reflects out of the installed candidate tree.
     *
     * @var list<string>
     */
    public const CRITICAL_PACKAGES = [
        'waaseyaa/access',
        'waaseyaa/auth',
        'waaseyaa/entity',
        'waaseyaa/entity-storage',
        'waaseyaa/field',
        'waaseyaa/foundation',
        'waaseyaa/routing',
        'waaseyaa/user',
    ];

    // ---------------------------------------------------------------------
    // Input validation. Everything below assumes these already ran.
    // ---------------------------------------------------------------------

    public static function assertSha(string $sha): void
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $sha) !== 1) {
            throw new RuntimeException('Framework commit must be exactly 40 lowercase hexadecimal characters.');
        }
    }

    public static function assertTrustMode(string $mode): void
    {
        if ($mode !== self::TRUST_MERGED_MAIN && $mode !== self::TRUST_REVIEW_CANDIDATE) {
            throw new RuntimeException('trust_mode must be merged-main or review-candidate.');
        }
    }

    /**
     * A released Framework version. Synthetic candidate versions, branch
     * aliases, dev constraints, and build metadata are all refused here so a
     * candidate can never be laundered into the adoption lane.
     *
     * @return array{constraint: string, lock: string}
     */
    public static function assertReleasedVersion(string $version): array
    {
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?)$/D', $version, $matches) !== 1) {
            throw new RuntimeException(
                'framework_version must be a published release such as v0.1.0-alpha.297; got ' . $version . '.',
            );
        }
        $plain = $matches[1];
        if (str_contains($version, '+') || str_contains($version, 'dev') || str_contains($plain, '999999')) {
            throw new RuntimeException('Refusing a synthetic or development Framework version: ' . $version . '.');
        }

        return ['constraint' => '^' . $plain, 'lock' => 'v' . $plain];
    }

    public static function assertTrustedCommit(
        string $frameworkRoot,
        string $sha,
        string $trustMode,
        string $mainRef = self::DEFAULT_MAIN_REF,
    ): void {
        self::assertSha($sha);
        self::assertTrustMode($trustMode);
        $frameworkRoot = self::realDirectory($frameworkRoot, 'framework root');
        if (self::git($frameworkRoot, ['rev-parse', 'HEAD']) !== $sha) {
            throw new RuntimeException('Framework checkout does not match the requested commit.');
        }
        if (self::git($frameworkRoot, ['status', '--porcelain']) !== '') {
            throw new RuntimeException('Framework checkout must be clean.');
        }
        if ($trustMode !== self::TRUST_MERGED_MAIN) {
            return;
        }
        if (self::execute(['git', '-C', $frameworkRoot, 'rev-parse', '--verify', $mainRef])['status'] !== 0) {
            throw new RuntimeException('merged-main requires a fetched waaseyaa/framework origin/main ref.');
        }
        if (self::execute(['git', '-C', $frameworkRoot, 'merge-base', '--is-ancestor', $sha, $mainRef])['status'] !== 0) {
            throw new RuntimeException(
                'merged-main refuses ' . $sha . ': it is not an ancestor of fetched waaseyaa/framework origin/main. '
                . 'Use review-candidate to authorize it explicitly as untrusted code.',
            );
        }
    }

    /**
     * Anokii's root manifest must declare exactly its own two path
     * repositories and no others. Both lanes call this before touching
     * Composer so an injected path overlay, the very thing PR #22 had to do by
     * hand, cannot slip in unnoticed.
     *
     * @param array<string, mixed> $composer
     */
    public static function assertAnokiiRepositoryShape(array $composer): void
    {
        $repositories = $composer['repositories'] ?? [];
        if (!is_array($repositories)) {
            throw new RuntimeException('Root composer.json repositories must be an array.');
        }
        $paths = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }
            $url = $repository['url'] ?? null;
            if (!is_string($url)) {
                throw new RuntimeException('A path repository declares no url.');
            }
            $paths[] = $url;
        }
        sort($paths);
        $expected = self::ANOKII_PATH_REPOSITORIES;
        sort($expected);
        if ($paths !== $expected) {
            throw new RuntimeException(
                'Refusing an unexpected path repository set. Expected ' . implode(', ', $expected)
                . '; found ' . ($paths === [] ? '(none)' : implode(', ', $paths)) . '.',
            );
        }
    }

    /**
     * Root waaseyaa/* requirements that the adoption lane is allowed to move.
     * Anokii's own split packages are excluded by name.
     *
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    public static function rootFrameworkRequirements(array $composer): array
    {
        $require = $composer['require'] ?? [];
        if (!is_array($require)) {
            throw new RuntimeException('Root composer.json require must be an object.');
        }
        $names = [];
        foreach (array_keys($require) as $name) {
            if (is_string($name) && self::isFrameworkPackage($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        if ($names === []) {
            throw new RuntimeException('Root composer.json requires no Framework packages.');
        }

        return $names;
    }

    public static function isFrameworkPackage(string $name): bool
    {
        return str_starts_with($name, 'waaseyaa/')
            && !str_starts_with($name, self::ANOKII_PACKAGE_PREFIX);
    }

    // ---------------------------------------------------------------------
    // Candidate lane.
    // ---------------------------------------------------------------------

    /**
     * @return array{
     *   sha: string,
     *   version: string,
     *   packages: array<string, array{path: string, type: string, tree: string}>,
     *   locked_candidates: list<string>
     * }
     */
    public static function plan(string $consumerRoot, string $frameworkRoot, string $sha): array
    {
        self::assertSha($sha);
        $consumerRoot = self::realDirectory($consumerRoot, 'consumer root');
        $frameworkRoot = self::realDirectory($frameworkRoot, 'framework root');

        if (self::git($frameworkRoot, ['rev-parse', 'HEAD']) !== $sha) {
            throw new RuntimeException('Framework checkout does not match the requested commit.');
        }
        if (self::git($frameworkRoot, ['status', '--porcelain']) !== '') {
            throw new RuntimeException('Framework checkout must be clean.');
        }

        $packages = [];
        foreach (glob($frameworkRoot . '/packages/*/composer.json') ?: [] as $composerPath) {
            $composer = self::json($composerPath);
            $name = $composer['name'] ?? null;
            if (!is_string($name) || preg_match('/^waaseyaa\/[a-z0-9-]+$/D', $name) !== 1) {
                continue;
            }
            if (str_starts_with($name, self::ANOKII_PACKAGE_PREFIX)) {
                throw new RuntimeException(
                    'The Framework checkout exposes ' . $name . '. Anokii split packages are owned by this '
                    . 'repository and must never be resolved from a Framework candidate.',
                );
            }
            $relative = self::relativePath($frameworkRoot, dirname($composerPath));
            if (isset($packages[$name])) {
                throw new RuntimeException('Duplicate Framework package ' . $name . '.');
            }
            $type = $composer['type'] ?? 'library';
            $packages[$name] = [
                'path' => $relative,
                'type' => is_string($type) ? $type : 'library',
                'tree' => self::git($frameworkRoot, ['rev-parse', $sha . ':' . $relative]),
            ];
        }
        ksort($packages);
        if ($packages === []) {
            throw new RuntimeException('Framework checkout exposes no split packages.');
        }

        $lockedCandidates = [];
        foreach (self::packageMap(self::json($consumerRoot . '/composer.lock')) as $name => $_package) {
            if (isset($packages[$name])) {
                $lockedCandidates[] = $name;
            }
        }
        sort($lockedCandidates);
        if ($lockedCandidates === []) {
            throw new RuntimeException('Anokii lock contains no Framework split packages.');
        }

        return [
            'sha' => $sha,
            'version' => '0.1.0-alpha.999999+candidate.' . substr($sha, 0, 12),
            'packages' => $packages,
            'locked_candidates' => $lockedCandidates,
        ];
    }

    /**
     * Install the candidate into a disposable Anokii clone. Canonical Composer
     * inputs are restored before this method returns, whatever happens, so the
     * proof never mutates composer.json or composer.lock.
     *
     * @return array<string, mixed>
     */
    public static function install(
        string $projectRoot,
        string $consumerRoot,
        string $frameworkRoot,
        string $sha,
        string $trustMode,
        string $mainRef = self::DEFAULT_MAIN_REF,
    ): array {
        self::assertSha($sha);
        self::assertTrustMode($trustMode);
        $projectRoot = self::realDirectory($projectRoot, 'project root');
        $consumerRoot = self::realDirectory($consumerRoot, 'consumer root');
        $frameworkRoot = self::realDirectory($frameworkRoot, 'framework root');
        if ($consumerRoot === $projectRoot) {
            throw new RuntimeException('Refusing to alter the canonical Anokii checkout.');
        }
        if (!is_dir($consumerRoot . '/.git')) {
            throw new RuntimeException('Consumer root must be a Git clone.');
        }
        if (self::git($consumerRoot, ['status', '--porcelain']) !== '') {
            throw new RuntimeException('Consumer clone must be clean before candidate preparation.');
        }
        self::assertTrustedCommit($frameworkRoot, $sha, $trustMode, $mainRef);

        $composerPath = $consumerRoot . '/composer.json';
        $lockPath = $consumerRoot . '/composer.lock';
        $canonicalComposer = self::read($composerPath);
        $canonicalLock = self::read($lockPath);
        $composer = self::decode($canonicalComposer, 'canonical Composer manifest');
        $originalLock = self::decode($canonicalLock, 'canonical Composer lock');
        self::assertAnokiiRepositoryShape($composer);

        $plan = self::plan($consumerRoot, $frameworkRoot, $sha);

        $candidateRepository = [
            'type' => 'path',
            'url' => $frameworkRoot . '/packages/*',
            'canonical' => true,
            'options' => [
                'symlink' => false,
                'versions' => array_fill_keys(array_keys($plan['packages']), $plan['version']),
                'reference' => 'none',
            ],
        ];
        $repositories = is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [];
        array_unshift($repositories, $candidateRepository);
        $composer['repositories'] = $repositories;
        foreach ($plan['locked_candidates'] as $name) {
            $composer['require'][$name] = $plan['version'];
        }
        // Anokii's own dev-main requirements are never rewritten above because
        // locked_candidates excludes them by name.
        foreach (self::ANOKII_DEV_MAIN_PACKAGES as $name) {
            if (($composer['require'][$name] ?? null) !== 'dev-main') {
                throw new RuntimeException($name . ' must stay required at dev-main during a candidate proof.');
            }
        }
        $composer['config']['allow-plugins'] = false;

        $prepared = false;
        $previousMirror = getenv('COMPOSER_MIRROR_PATH_REPOS');
        putenv('COMPOSER_MIRROR_PATH_REPOS=1');

        try {
            self::write($composerPath, self::encode($composer));
            self::run([
                'composer',
                'update',
                ...$plan['locked_candidates'],
                '--with-all-dependencies',
                '--minimal-changes',
                '--prefer-dist',
                '--no-interaction',
                '--no-progress',
                '--no-scripts',
                '--no-plugins',
            ], $consumerRoot);

            $candidateLock = self::read($lockPath);
            $resolved = self::packageMap(self::decode($candidateLock, 'candidate Composer lock'));
            $original = self::packageMap($originalLock);

            $candidateNames = [];
            foreach ($resolved as $name => $package) {
                if (!isset($plan['packages'][$name])) {
                    continue;
                }
                if (($package['version'] ?? null) !== $plan['version']) {
                    throw new RuntimeException($name . ' did not resolve to the candidate version.');
                }
                $candidateNames[] = $name;
            }
            sort($candidateNames);
            foreach ($plan['locked_candidates'] as $name) {
                if (!in_array($name, $candidateNames, true)) {
                    throw new RuntimeException('Locked Framework package ' . $name . ' was not installed from the candidate.');
                }
            }

            self::assertAnokiiIdentitiesPreserved($original, $resolved);

            // A Framework commit may legitimately add or remove transitive
            // dependencies through its own package manifests. Record those
            // candidate-caused graph changes, but refuse identity movement for
            // an external package present on both sides: that is an unrelated
            // repin, not a dependency newly required or retired by Framework.
            $resolvedExternal = self::externalIdentities($resolved, $plan['packages']);
            $classification = self::classify($original, $resolved);
            $externalDependencyChanges = self::candidateExternalDependencyChanges($classification);

            $evidence = [
                'schema' => self::CANDIDATE_SCHEMA,
                'released_compatibility' => false,
                'framework_repository' => self::FRAMEWORK_REPOSITORY,
                'framework_sha' => $sha,
                'trust_mode' => $trustMode,
                'candidate_version' => $plan['version'],
                'consumer_repository' => 'https://github.com/waaseyaa/anokii',
                'consumer_sha' => self::git($consumerRoot, ['rev-parse', 'HEAD']),
                'canonical_composer_sha256' => hash('sha256', $canonicalComposer),
                'canonical_lock_sha256' => hash('sha256', $canonicalLock),
                'candidate_lock_sha256' => hash('sha256', $candidateLock),
                'candidate_packages' => array_map(
                    static fn(string $name): array => [
                        'name' => $name,
                        'path' => $plan['packages'][$name]['path'],
                        'type' => $plan['packages'][$name]['type'],
                        'tree' => $plan['packages'][$name]['tree'],
                    ],
                    $candidateNames,
                ),
                'anokii_dev_main_packages' => self::anokiiIdentities($resolved),
                'external_package_count' => count($resolvedExternal),
                'external_dependency_changes' => $externalDependencyChanges,
            ];
            $directory = $consumerRoot . '/var/framework-candidate';
            self::makeDirectory($directory);
            self::writeEvidence($directory, $evidence);
            $prepared = true;

            return $evidence;
        } finally {
            if ($previousMirror === false) {
                putenv('COMPOSER_MIRROR_PATH_REPOS');
            } else {
                putenv('COMPOSER_MIRROR_PATH_REPOS=' . $previousMirror);
            }
            self::write($composerPath, $canonicalComposer);
            self::write($lockPath, $canonicalLock);
            if (!$prepared) {
                self::discardPartialInstall($consumerRoot);
            }
        }
    }

    /**
     * Re-derive custody from the installed tree. This runs after install() and
     * proves the vendor tree really is the requested commit, that canonical
     * inputs came back byte for byte, and that Anokii's own packages are still
     * the local dev-main path packages.
     *
     * @return array<string, mixed>
     */
    public static function verify(
        string $consumerRoot,
        string $frameworkRoot,
        string $sha,
        ?string $expectedManifestSha256 = null,
    ): array {
        self::assertSha($sha);
        $consumerRoot = self::realDirectory($consumerRoot, 'consumer root');
        $frameworkRoot = self::realDirectory($frameworkRoot, 'framework root');
        $manifestPath = $consumerRoot . '/var/framework-candidate/' . self::MANIFEST_NAME;
        $manifestHash = self::assertManifestHash($manifestPath);
        if (is_string($expectedManifestSha256) && $expectedManifestSha256 !== ''
            && !hash_equals($expectedManifestSha256, $manifestHash)) {
            throw new RuntimeException('Custody manifest does not match the sealed hash.');
        }

        $manifest = self::json($manifestPath);
        if (($manifest['schema'] ?? null) !== self::CANDIDATE_SCHEMA) {
            throw new RuntimeException('Candidate evidence manifest is invalid.');
        }
        if (($manifest['released_compatibility'] ?? null) !== false) {
            throw new RuntimeException('Candidate evidence must never claim released compatibility.');
        }
        if (($manifest['framework_sha'] ?? null) !== $sha) {
            throw new RuntimeException('Candidate evidence does not match the requested Framework commit.');
        }
        self::assertTrustMode((string) ($manifest['trust_mode'] ?? ''));
        if (!hash_equals((string) ($manifest['canonical_composer_sha256'] ?? ''), (string) hash_file('sha256', $consumerRoot . '/composer.json'))
            || !hash_equals((string) ($manifest['canonical_lock_sha256'] ?? ''), (string) hash_file('sha256', $consumerRoot . '/composer.lock'))) {
            throw new RuntimeException('Canonical Composer inputs were not restored exactly.');
        }

        $autoload = $consumerRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Consumer vendor autoload is missing.');
        }
        require $autoload;

        $candidateVersion = (string) ($manifest['candidate_version'] ?? '');
        $reflections = [];
        $verified = [];
        foreach ($manifest['candidate_packages'] ?? [] as $candidate) {
            if (!is_array($candidate) || !is_string($candidate['name'] ?? null) || !is_string($candidate['path'] ?? null)) {
                throw new RuntimeException('Candidate package evidence is malformed.');
            }
            $name = $candidate['name'];
            if (!self::isFrameworkPackage($name)) {
                throw new RuntimeException($name . ' is not a Framework package and cannot be candidate evidence.');
            }
            $expectedTree = self::git($frameworkRoot, ['rev-parse', $sha . ':' . $candidate['path']]);
            if (!hash_equals((string) ($candidate['tree'] ?? ''), $expectedTree)) {
                throw new RuntimeException('Framework tree changed for ' . $name . '.');
            }
            if (!InstalledVersions::isInstalled($name)
                || InstalledVersions::getPrettyVersion($name) !== $candidateVersion) {
                throw new RuntimeException($name . ' is not installed at the candidate version.');
            }

            // Framework metapackages carry no files, so there is no vendor tree
            // to mirror or reflect. Their identity is the resolved candidate
            // version plus the source tree hash asserted above.
            if (($candidate['type'] ?? 'library') === 'metapackage') {
                if (in_array($name, self::CRITICAL_PACKAGES, true)) {
                    throw new RuntimeException($name . ' is a metapackage and cannot be a critical reflected package.');
                }
                $verified[] = [
                    'name' => $name,
                    'source' => $candidate['path'],
                    'install' => null,
                    'type' => 'metapackage',
                    'tree' => $expectedTree,
                ];

                continue;
            }

            $installPath = realpath((string) InstalledVersions::getInstallPath($name));
            $expected = realpath($consumerRoot . '/vendor/' . $name);
            if (!is_string($installPath) || !is_string($expected) || $installPath !== $expected) {
                throw new RuntimeException($name . ' install path is outside the candidate vendor tree.');
            }
            if (is_link($installPath)) {
                throw new RuntimeException($name . ' must be mirrored into vendor, not symlinked.');
            }
            if ((self::json($installPath . '/composer.json')['name'] ?? null) !== $name) {
                throw new RuntimeException($name . ' installed metadata is invalid.');
            }
            if (in_array($name, self::CRITICAL_PACKAGES, true)) {
                $symbol = self::firstLoadableSymbol($installPath, self::json($installPath . '/composer.json'));
                if ($symbol === null) {
                    throw new RuntimeException('No reflected PSR-4 symbol was found for ' . $name . '.');
                }
                $file = realpath((string) new ReflectionClass($symbol)->getFileName());
                if (!is_string($file) || !str_starts_with($file, $installPath . DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException($symbol . ' did not reflect from the installed candidate package.');
                }
                $reflections[$name] = ['symbol' => $symbol, 'file' => substr($file, strlen($consumerRoot) + 1)];
            }
            $verified[] = [
                'name' => $name,
                'source' => $candidate['path'],
                'install' => substr($installPath, strlen($consumerRoot) + 1),
                'type' => (string) ($candidate['type'] ?? 'library'),
                'tree' => $expectedTree,
            ];
        }
        foreach (self::CRITICAL_PACKAGES as $name) {
            if (!isset($reflections[$name])) {
                throw new RuntimeException('Critical Framework package ' . $name . ' was not reflected.');
            }
        }

        // Anokii's own capability packages must still be the local dev-main
        // path install, reflecting out of the consumer clone rather than out of
        // anything the candidate supplied.
        $anokii = [];
        foreach (self::ANOKII_DEV_MAIN_PACKAGES as $name) {
            if (!InstalledVersions::isInstalled($name)) {
                throw new RuntimeException($name . ' is not installed in the candidate consumer.');
            }
            $version = (string) InstalledVersions::getPrettyVersion($name);
            if ($version !== 'dev-main') {
                throw new RuntimeException($name . ' must stay at dev-main; found ' . $version . '.');
            }
            $anokii[$name] = $version;
        }

        return [
            'schema' => 'anokii.framework-candidate-verification.v1',
            'framework_sha' => $sha,
            'trust_mode' => (string) $manifest['trust_mode'],
            'manifest_sha256' => $manifestHash,
            'package_count' => count($verified),
            'packages' => $verified,
            'reflections' => $reflections,
            'anokii_dev_main_packages' => $anokii,
        ];
    }

    // ---------------------------------------------------------------------
    // Released-adoption lane.
    // ---------------------------------------------------------------------

    /**
     * Move only the released Framework cohort to $version inside a disposable
     * clone, classify every lock movement, and refuse anything unrelated.
     *
     * @return array<string, mixed>
     */
    public static function adopt(string $projectRoot, string $workRoot, string $version): array
    {
        $target = self::assertReleasedVersion($version);
        $projectRoot = self::realDirectory($projectRoot, 'project root');
        $workRoot = self::realDirectory($workRoot, 'work root');
        if ($workRoot === $projectRoot) {
            throw new RuntimeException('Refusing to adopt into the canonical Anokii checkout.');
        }
        if (!is_dir($workRoot . '/.git')) {
            throw new RuntimeException('Work root must be a Git clone.');
        }
        if (self::git($workRoot, ['status', '--porcelain']) !== '') {
            throw new RuntimeException('Work clone must be clean before adoption.');
        }

        $composerPath = $workRoot . '/composer.json';
        $lockPath = $workRoot . '/composer.lock';
        $canonicalComposer = self::read($composerPath);
        $canonicalLock = self::read($lockPath);
        $composer = self::decode($canonicalComposer, 'canonical Composer manifest');
        $originalLock = self::decode($canonicalLock, 'canonical Composer lock');
        self::assertAnokiiRepositoryShape($composer);
        self::assertNoCandidateResidue($workRoot, $originalLock);

        $roots = self::rootFrameworkRequirements($composer);
        $requireBefore = $composer['require'];
        foreach ($roots as $name) {
            $composer['require'][$name] = $target['constraint'];
        }
        if (array_keys($requireBefore) !== array_keys($composer['require'])) {
            throw new RuntimeException('Adoption may not add or remove root requirements.');
        }
        foreach ($requireBefore as $name => $constraint) {
            if (!in_array($name, $roots, true) && $composer['require'][$name] !== $constraint) {
                throw new RuntimeException('Adoption changed a non-Framework root requirement: ' . $name . '.');
            }
        }

        $applied = false;
        try {
            self::write($composerPath, self::encode($composer));
            self::run([
                'composer',
                'update',
                ...$roots,
                '--with-all-dependencies',
                '--minimal-changes',
                '--prefer-dist',
                '--no-interaction',
                '--no-progress',
                '--no-scripts',
                '--no-plugins',
            ], $workRoot);

            $adoptedLock = self::read($lockPath);
            $resolved = self::packageMap(self::decode($adoptedLock, 'adopted Composer lock'));
            $original = self::packageMap($originalLock);

            self::assertNoCandidateResidue($workRoot, self::decode($adoptedLock, 'adopted Composer lock'));
            self::assertAnokiiIdentitiesPreserved($original, $resolved);

            $classification = self::classify($original, $resolved);
            $refusals = [];
            foreach (['anokii', 'external'] as $class) {
                foreach (['added', 'removed', 'changed'] as $movement) {
                    foreach ($classification[$class][$movement] as $entry) {
                        $refusals[] = $class . ' ' . $movement . ' ' . $entry['name'];
                    }
                }
            }
            if ($refusals !== []) {
                throw new RuntimeException(
                    'Refusing unrelated Composer movement: ' . implode('; ', $refusals) . '.',
                );
            }

            // The cohort must be uniform and exactly the requested release.
            // Anything below it is a partial cohort. Anything above it means a
            // newer release exists and the operator must name that one, so a
            // run can never quietly adopt a version nobody asked for.
            $offCohort = [];
            foreach ($resolved as $name => $package) {
                if (!self::isFrameworkPackage($name)) {
                    continue;
                }
                if (($package['dist']['type'] ?? null) === 'path') {
                    throw new RuntimeException($name . ' resolved from a path repository; released adoption refuses that.');
                }
                if (($package['version'] ?? null) !== $target['lock']) {
                    $offCohort[] = $name . '@' . ((string) ($package['version'] ?? '?'));
                }
            }
            if ($offCohort !== []) {
                throw new RuntimeException(
                    'Partial cohort refused. Every Framework package must resolve to exactly ' . $target['lock']
                    . '. These did not: ' . implode(', ', $offCohort) . '. A package below the target is not published '
                    . 'at that release; a package above it means a newer release exists, so dispatch that release '
                    . 'instead of this one.',
                );
            }

            $evidence = [
                'schema' => self::ADOPTION_SCHEMA,
                'released_compatibility' => true,
                'framework_repository' => self::FRAMEWORK_REPOSITORY,
                'framework_version' => $target['lock'],
                'framework_constraint' => $target['constraint'],
                'consumer_sha' => self::git($workRoot, ['rev-parse', 'HEAD']),
                'root_requirements_updated' => $roots,
                'previous_composer_sha256' => hash('sha256', $canonicalComposer),
                'previous_lock_sha256' => hash('sha256', $canonicalLock),
                'adopted_composer_sha256' => (string) hash_file('sha256', $composerPath),
                'adopted_lock_sha256' => hash('sha256', $adoptedLock),
                'classification' => $classification,
                'anokii_dev_main_packages' => self::anokiiIdentities($resolved),
            ];
            $directory = $workRoot . '/var/framework-adoption';
            self::makeDirectory($directory);
            self::writeEvidence($directory, $evidence);
            copy($composerPath, $directory . '/composer.json');
            copy($lockPath, $directory . '/composer.lock');
            $applied = true;

            return $evidence;
        } finally {
            if (!$applied) {
                self::write($composerPath, $canonicalComposer);
                self::write($lockPath, $canonicalLock);
            }
        }
    }

    /**
     * Candidate proofs must never be laundered into a released-compatibility
     * claim, so the adoption lane refuses to run against a tree that carries
     * candidate residue.
     *
     * @param array<string, mixed> $lock
     */
    public static function assertNoCandidateResidue(string $root, array $lock): void
    {
        if (is_dir($root . '/var/framework-candidate')) {
            throw new RuntimeException(
                'Candidate custody evidence is present. A candidate proof is not released compatibility.',
            );
        }
        foreach (self::packageMap($lock) as $name => $package) {
            if (!self::isFrameworkPackage($name)) {
                continue;
            }
            $lockedVersion = (string) ($package['version'] ?? '');
            if (str_contains($lockedVersion, '+candidate.') || str_contains($lockedVersion, '999999')) {
                throw new RuntimeException('Lock carries a synthetic candidate version for ' . $name . '.');
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $original
     * @param array<string, array<string, mixed>> $resolved
     * @return array<string, array{added: list<array{name: string, to: string}>, removed: list<array{name: string, from: string}>, changed: list<array{name: string, from: string, to: string}>, unchanged: list<string>}>
     */
    public static function classify(array $original, array $resolved): array
    {
        $empty = ['added' => [], 'removed' => [], 'changed' => [], 'unchanged' => []];
        $classification = ['framework' => $empty, 'anokii' => $empty, 'external' => $empty];

        foreach (array_keys($resolved + $original) as $name) {
            $class = self::classOf((string) $name);
            $before = $original[$name] ?? null;
            $after = $resolved[$name] ?? null;
            if ($before === null && $after !== null) {
                $classification[$class]['added'][] = ['name' => $name, 'to' => (string) ($after['version'] ?? '?')];
                continue;
            }
            if ($after === null && $before !== null) {
                $classification[$class]['removed'][] = ['name' => $name, 'from' => (string) ($before['version'] ?? '?')];
                continue;
            }
            if (self::identity((array) $before) === self::identity((array) $after)) {
                $classification[$class]['unchanged'][] = $name;
                continue;
            }
            $classification[$class]['changed'][] = [
                'name' => $name,
                'from' => (string) ($before['version'] ?? '?'),
                'to' => (string) ($after['version'] ?? '?'),
            ];
        }
        foreach ($classification as $class => $movements) {
            foreach ($movements as $movement => $entries) {
                usort($entries, static fn(mixed $a, mixed $b): int => strcmp(
                    is_array($a) ? (string) $a['name'] : (string) $a,
                    is_array($b) ? (string) $b['name'] : (string) $b,
                ));
                $classification[$class][$movement] = array_values($entries);
            }
        }

        return $classification;
    }

    /**
     * Candidate commits may add or remove external transitive dependencies,
     * but must not repin an external package already present in both locks.
     *
     * @param array<string, array{added: list<array{name: string, to: string}>, removed: list<array{name: string, from: string}>, changed: list<array{name: string, from: string, to: string}>, unchanged: list<string>}> $classification
     * @return array{added: list<array{name: string, to: string}>, removed: list<array{name: string, from: string}>, changed: array{}}
     */
    public static function candidateExternalDependencyChanges(array $classification): array
    {
        $external = $classification['external'] ?? null;
        if (!is_array($external)
            || !is_array($external['added'] ?? null)
            || !is_array($external['removed'] ?? null)
            || !is_array($external['changed'] ?? null)) {
            throw new RuntimeException('External dependency classification is malformed.');
        }

        if ($external['changed'] !== []) {
            $drift = [];
            foreach ($external['changed'] as $entry) {
                $drift[] = $entry['name'] . ' (' . $entry['from'] . ' -> ' . $entry['to'] . ')';
            }
            throw new RuntimeException(
                'Candidate resolution repinned existing non-Framework dependencies: ' . implode('; ', $drift)
                . '. Added or removed transitive dependencies are candidate evidence, but an existing package '
                . 'identity must stay pinned until the released adoption path changes it explicitly.',
            );
        }

        return [
            'added' => $external['added'],
            'removed' => $external['removed'],
            'changed' => [],
        ];
    }

    public static function classOf(string $name): string
    {
        if (str_starts_with($name, self::ANOKII_PACKAGE_PREFIX)) {
            return 'anokii';
        }

        return str_starts_with($name, 'waaseyaa/') ? 'framework' : 'external';
    }

    /**
     * @param array<string, array<string, mixed>> $original
     * @param array<string, array<string, mixed>> $resolved
     */
    public static function assertAnokiiIdentitiesPreserved(array $original, array $resolved): void
    {
        foreach (self::ANOKII_DEV_MAIN_PACKAGES as $name) {
            if (!isset($resolved[$name])) {
                throw new RuntimeException($name . ' disappeared from the lock.');
            }
            if (($resolved[$name]['version'] ?? null) !== 'dev-main') {
                throw new RuntimeException(
                    $name . ' must stay locked at dev-main; found ' . ((string) ($resolved[$name]['version'] ?? '?')) . '.',
                );
            }
            if (($resolved[$name]['dist']['type'] ?? null) !== 'path') {
                throw new RuntimeException($name . ' must stay a local path package.');
            }
            if (isset($original[$name]) && self::identity($original[$name]) !== self::identity($resolved[$name])) {
                throw new RuntimeException($name . ' changed identity; Anokii dev-main packages are never repinned here.');
            }
        }
        foreach (array_keys($resolved) as $name) {
            if (str_starts_with((string) $name, self::ANOKII_PACKAGE_PREFIX)
                && !in_array($name, self::ANOKII_DEV_MAIN_PACKAGES, true)) {
                throw new RuntimeException('Unexpected Anokii split package in the lock: ' . $name . '.');
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $resolved
     * @return array<string, array{version: string, dist_type: string, dist_url: string}>
     */
    public static function anokiiIdentities(array $resolved): array
    {
        $identities = [];
        foreach (self::ANOKII_DEV_MAIN_PACKAGES as $name) {
            $package = $resolved[$name] ?? [];
            $identities[$name] = [
                'version' => (string) ($package['version'] ?? ''),
                'dist_type' => (string) ($package['dist']['type'] ?? ''),
                'dist_url' => (string) ($package['dist']['url'] ?? ''),
            ];
        }

        return $identities;
    }

    // ---------------------------------------------------------------------
    // Custody evidence.
    // ---------------------------------------------------------------------

    public static function assertManifestHash(string $manifestPath): string
    {
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Custody manifest is missing.');
        }
        $hash = hash_file('sha256', $manifestPath);
        if (!is_string($hash)) {
            throw new RuntimeException('Could not hash the custody manifest.');
        }
        if (!hash_equals(rtrim(self::read($manifestPath . '.sha256')), $hash)) {
            throw new RuntimeException('Custody manifest hash mismatch.');
        }

        return $hash;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public static function writeEvidence(string $directory, array $evidence): string
    {
        $path = $directory . '/' . self::MANIFEST_NAME;
        $json = self::encode($evidence);
        self::write($path, $json);
        $hash = hash('sha256', $json);
        self::write($path . '.sha256', $hash . "\n");
        if (!chmod($path, 0o444) || !chmod($path . '.sha256', 0o444)) {
            throw new RuntimeException('Could not freeze custody evidence files.');
        }

        return $hash;
    }

    // ---------------------------------------------------------------------
    // Process and filesystem helpers.
    // ---------------------------------------------------------------------

    /**
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, status: int, stdout_dropped: int, stderr_dropped: int}
     */
    public static function execute(
        array $command,
        ?string $workingDirectory = null,
        int $timeoutSeconds = self::PROCESS_TIMEOUT_SECONDS,
    ): array {
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('Process timeout must be at least one second.');
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start process: ' . implode(' ', $command));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffers = [1 => '', 2 => ''];
        $dropped = [1 => 0, 2 => 0];
        $open = [1 => true, 2 => true];
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while ($open[1] || $open[2]) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    $timedOut = true;
                    break;
                }
                $read = [];
                foreach ([1, 2] as $fd) {
                    if ($open[$fd] && is_resource($pipes[$fd])) {
                        $read[] = $pipes[$fd];
                    }
                }
                if ($read === []) {
                    break;
                }
                $write = null;
                $except = null;
                $wait = min(0.1, $remaining);
                if (@stream_select($read, $write, $except, (int) $wait, (int) round(($wait - (int) $wait) * 1_000_000)) === false) {
                    continue;
                }
                foreach ($read as $stream) {
                    $fd = $stream === $pipes[1] ? 1 : 2;
                    $chunk = fread($stream, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $buffers[$fd] .= $chunk;
                        $overflow = strlen($buffers[$fd]) - self::PROCESS_OUTPUT_BOUND;
                        if ($overflow > 0) {
                            $dropped[$fd] += $overflow;
                            $buffers[$fd] = substr($buffers[$fd], -self::PROCESS_OUTPUT_BOUND);
                        }
                    }
                    if (feof($stream)) {
                        $open[$fd] = false;
                    }
                }
            }
            if ($timedOut) {
                proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
            }
        } finally {
            foreach ([1, 2] as $fd) {
                if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                    fclose($pipes[$fd]);
                }
            }
        }

        $exit = proc_close($process);
        $result = [
            'stdout' => $buffers[1],
            'stderr' => $buffers[2],
            'status' => $timedOut ? 124 : $exit,
            'stdout_dropped' => $dropped[1],
            'stderr_dropped' => $dropped[2],
        ];
        if ($timedOut) {
            throw new RuntimeException(
                'Process timed out after ' . $timeoutSeconds . 's: ' . implode(' ', $command) . "\n"
                . self::diagnosticOutput($result),
            );
        }

        return $result;
    }

    /** @param list<string> $command */
    private static function run(
        array $command,
        ?string $workingDirectory = null,
        int $timeoutSeconds = self::PROCESS_TIMEOUT_SECONDS,
    ): string {
        $result = self::execute($command, $workingDirectory, $timeoutSeconds);
        if ($result['status'] !== 0) {
            throw new RuntimeException(
                'Process exited ' . $result['status'] . ': ' . implode(' ', $command) . "\n"
                . self::diagnosticOutput($result),
            );
        }

        return $result['stdout'];
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        return trim(self::run(['git', '-C', $root, ...$arguments], null, self::GIT_TIMEOUT_SECONDS));
    }

    /**
     * @param array{stdout: string, stderr: string, status: int, stdout_dropped: int, stderr_dropped: int} $result
     */
    private static function diagnosticOutput(array $result): string
    {
        return sprintf(
            "stdout_tail dropped=%d\n%s\nstderr_tail dropped=%d\n%s",
            $result['stdout_dropped'],
            self::redact(self::tail($result['stdout'])),
            $result['stderr_dropped'],
            self::redact(self::tail($result['stderr'])),
        );
    }

    private static function tail(string $text): string
    {
        return strlen($text) <= self::PROCESS_DIAGNOSTIC_TAIL
            ? $text
            : substr($text, -self::PROCESS_DIAGNOSTIC_TAIL);
    }

    private static function redact(string $text): string
    {
        $redacted = preg_replace(
            '/(?i)(authorization|token|secret|password|api[_-]?key)\s*[:=]\s*\S+/',
            '$1=[redacted]',
            $text,
        );

        return is_string($redacted) ? $redacted : $text;
    }

    private static function discardPartialInstall(string $consumerRoot): void
    {
        foreach (['vendor', 'var/framework-candidate'] as $relative) {
            $path = $consumerRoot . '/' . $relative;
            if (!file_exists($path)) {
                continue;
            }
            $root = realpath($consumerRoot);
            $resolved = realpath($path);
            if (!is_string($root) || !is_string($resolved) || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Refusing to remove ' . $relative . ' outside the consumer clone.');
            }
            self::deleteTree($resolved);
        }
    }

    /**
     * Remove a managed tree, restoring just enough mode to delete it.
     *
     * chmod() follows symbolic links, so a link is NEVER chmod'ed here: Anokii
     * installs its own dev-main splits by symlink, and chmod'ing
     * vendor/waaseyaa/anokii-core would silently change the mode of
     * packages/core in the consumer clone. Links are unlinked directly.
     */
    private static function deleteTree(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (is_file($path)) {
            @chmod($path, 0o644);
            unlink($path);

            return;
        }
        @chmod($path, 0o755);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $target = $file->getPathname();
            if ($file->isLink()) {
                unlink($target);
            } elseif ($file->isFile()) {
                @chmod($target, 0o644);
                unlink($target);
            } else {
                @chmod($target, 0o755);
                rmdir($target);
            }
        }
        rmdir($path);
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     * @param array<string, mixed> $frameworkPackages
     * @return array<string, string>
     */
    private static function externalIdentities(array $packages, array $frameworkPackages): array
    {
        $identities = [];
        foreach ($packages as $name => $package) {
            if (isset($frameworkPackages[$name])) {
                continue;
            }
            $identities[$name] = self::identity($package);
        }
        ksort($identities);

        return $identities;
    }

    /** @param array<string, mixed> $package */
    private static function identity(array $package): string
    {
        return hash('sha256', json_encode([
            'version' => $package['version'] ?? null,
            'source' => $package['source'] ?? null,
            'dist' => $package['dist'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $lock
     * @return array<string, array<string, mixed>>
     */
    private static function packageMap(array $lock): array
    {
        $packages = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packages[$package['name']] = $package;
            }
        }
        ksort($packages);

        return $packages;
    }

    private static function relativePath(string $root, string $path): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $prefix)) {
            throw new RuntimeException('Package path escapes the Framework checkout.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($prefix)));
    }

    private static function realDirectory(string $path, string $label): string
    {
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException($label . ' does not exist.');
        }

        return $resolved;
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create ' . $path . '.');
        }
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read ' . $path . '.');
        }

        return $contents;
    }

    private static function write(string $path, string $contents): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing to write through a symbolic link: ' . $path . '.');
        }
        if (file_exists($path)) {
            @chmod($path, 0o644);
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write ' . $path . '.');
        }
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        return self::decode(self::read($path), $path);
    }

    /** @return array<string, mixed> */
    private static function decode(string $contents, string $label): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON document is not an object: ' . $label . '.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, mixed> $composer */
    private static function firstLoadableSymbol(string $installPath, array $composer): ?string
    {
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return null;
        }
        foreach ($psr4 as $prefix => $directories) {
            if (!is_string($prefix)) {
                continue;
            }
            foreach ((array) $directories as $directory) {
                if (!is_string($directory) || !is_dir($installPath . '/' . $directory)) {
                    continue;
                }
                $files = [];
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($installPath . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
                sort($files);
                foreach ($files as $file) {
                    foreach (self::declaredSymbols((string) file_get_contents($file)) as $symbol) {
                        if (str_starts_with($symbol, $prefix)
                            && (class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol))) {
                            return $symbol;
                        }
                    }
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function declaredSymbols(string $source): array
    {
        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;{\s]+)/m', $source, $matches) === 1) {
            $namespace = $matches[1] . '\\';
        }
        if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait)\s+(\w+)/m', $source, $matches) !== false) {
            return array_map(static fn(string $name): string => $namespace . $name, $matches[1] ?? []);
        }

        return [];
    }
}
