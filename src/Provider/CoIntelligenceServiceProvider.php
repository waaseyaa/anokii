<?php

declare(strict_types=1);

namespace Anokii\Provider;

use Anokii\CoIntelligence\ChatPromptBuilder;
use Anokii\CoIntelligence\ChatQueryLogSchema;
use Anokii\CoIntelligence\ChatVoice;
use Anokii\CoIntelligence\GraphRetriever;
use Anokii\CoIntelligence\SqliteChatQueryLog;
use Anokii\CoIntelligence\SqliteRateLimiter;
use Anokii\CoIntelligence\TopicVocabulary;
use Anokii\Config\DistributionConfig;
use Anokii\Config\TenancyMode;
use Anokii\Controller\AnokiiAdminController;
use Anokii\Controller\PublicChatController;
use Anokii\Entity\Community;
use Anokii\Entity\DocChunk;
use Anokii\Entity\Organization;
use Anokii\Entity\Place;
use Anokii\Entity\Project;
use Anokii\Entity\Service;
use Anokii\Entity\Topic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\AI\Agent\Provider\AnthropicProvider;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Wires the canonical Co-Intelligence engine and the public graph-chat surface,
 * driven by the distribution switch (config/anokii.yaml).
 *
 * - Always: registers the graph entity model (Community, Place, Organization,
 *   Service, Project, Topic, doc_chunk) and rebinds the LLM provider to Anthropic
 *   from the server-side key (framework NullLlmProvider stays the default when no
 *   key is set, so the controller reports "not configured" rather than erroring).
 * - Shared-graph tier (or the `public-graph-chat` module enabled): mounts the
 *   public POST /api/chat and the lean /admin/anokii admin.
 * - Sovereign tier: the gated workspace surface mounts its own routes (the
 *   workspace controllers remain app-provided until they are extracted in a later
 *   increment); this provider only contributes the shared engine and entities.
 *
 * The model provider is never forked here; only the binding is chosen. The DB is
 * pinned to the persistent SQLite file because resolve(DatabaseInterface) at
 * route-build time can hand back an ephemeral connection (the controller is built
 * once, not per request), which would defeat the rate limiter and the log.
 *
 * @api
 */
final class CoIntelligenceServiceProvider extends ServiceProvider
{
    /** Chat model, the locked Claude Sonnet default. */
    private const MODEL = 'claude-sonnet-4-6';

    /**
     * Route priority for /admin/anokii so it beats the framework admin SPA GET
     * catch-all at /admin/{path} (priority 0). Matches the sovereign workspace.
     */
    private const ROUTE_PRIORITY = 100;

    /** @var list<class-string> the package-canonical graph entity classes */
    private const ENTITY_CLASSES = [
        Topic::class,
        Place::class,
        Community::class,
        Organization::class,
        Service::class,
        Project::class,
        DocChunk::class,
    ];

    private ?DatabaseInterface $persistent = null;

    public function register(): void
    {
        // Distribution posture, safe-by-default sovereign when the file is absent.
        $this->singleton(
            DistributionConfig::class,
            fn(): DistributionConfig => DistributionConfig::fromFile($this->projectRoot . '/config/anokii.yaml'),
        );

        // The graph entity model. A package's entities are NOT auto-discovered
        // from the app's src/, so the provider registers them explicitly from
        // their attribute metadata, giving every consumer one shared shape.
        foreach (self::ENTITY_CLASSES as $class) {
            $this->entityType(EntityType::fromClass($class));
        }

        // Rebind the LLM provider to Anthropic from the server-side key. Left
        // untouched (framework NullLlmProvider) when no key is set.
        $key = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($key !== '') {
            $this->singleton(
                ProviderInterface::class,
                fn(): ProviderInterface => new AnthropicProvider($key, self::MODEL),
            );
        }
    }

    public function boot(): void
    {
        if (!$this->kernelPresent()) {
            return;
        }
        // Ensure the no-PII chat-log table on the persistent file. Wrapped so a
        // storage hiccup never takes down a page.
        try {
            new ChatQueryLogSchema($this->persistentDatabase())->ensure();
        } catch (\Throwable) {
            // best effort
        }
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        if (!$this->kernelPresent()) {
            return;
        }

        $config = $this->distributionConfig();
        $isShared = $config->tenancyMode() === TenancyMode::SharedGraph;

        // The public surface is the shared-graph tier (or an explicit opt-in).
        // The sovereign gated workspace contributes its own routes elsewhere.
        if (!$isShared && !$config->moduleEnabled('public-graph-chat')) {
            return;
        }

        $key = getenv('ANTHROPIC_API_KEY') ?: '';
        $configured = $key !== '';
        $webResearch = (getenv('ANOKII_WEB_RESEARCH') ?: '') === '1' && $configured;
        $db = $this->persistentDatabase();
        $chatConfig = $this->chatConfig();

        $chat = new PublicChatController(
            retriever: new GraphRetriever($db),
            prompts: new ChatPromptBuilder($this->chatVoice($chatConfig)),
            provider: $configured
                ? new AnthropicProvider($key, self::MODEL)
                : $this->resolve(ProviderInterface::class),
            limiter: new SqliteRateLimiter($db, $this->rateMaxRequests($chatConfig), $this->rateWindowSeconds($chatConfig)),
            logger: $this->resolve(LoggerInterface::class),
            queryLog: new SqliteChatQueryLog($db),
            topics: new TopicVocabulary(),
            configured: $configured,
            model: self::MODEL,
            communities: $this->vantages($chatConfig),
            defaultCommunity: $this->defaultVantage($chatConfig),
            webResearch: $webResearch,
            maxTokens: $this->positiveInt($chatConfig, 'max_output_tokens', PublicChatController::DEFAULT_MAX_TOKENS),
            maxTokensWeb: $this->positiveInt($chatConfig, 'max_output_tokens_web', PublicChatController::DEFAULT_MAX_TOKENS_WEB),
        );
        $router->addRoute(
            'anokii.chat',
            RouteBuilder::create('/api/chat')
                ->controller(fn(Request $request) => $chat->handle($request))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        // Lean admin: entity counts + the no-PII content-gap log. Mounted ONLY when
        // the `anokii-admin` module is explicitly enabled, so an install that
        // provides its own gated /admin/anokii (rhtcircle, oiatc) simply leaves the
        // module off and the package does not register a competing ungated route.
        // (Previously this also auto-mounted for any shared-graph install, which
        // left a public route those installs had to shadow.) The package route, if
        // enabled, is gated in production by the host's own /admin auth.
        if ($config->moduleEnabled('anokii-admin')) {
            $admin = new AnokiiAdminController($db, new SqliteChatQueryLog($db));
            $router->addRoute(
                'anokii.admin',
                RouteBuilder::create('/admin/anokii')
                    ->controller(fn(Request $request) => $admin->index($request))
                    ->allowAll()
                    ->methods('GET')
                    ->priority(self::ROUTE_PRIORITY)
                    ->build(),
            );
        }
    }

    private function distributionConfig(): DistributionConfig
    {
        $resolved = $this->resolve(DistributionConfig::class);

        return $resolved instanceof DistributionConfig
            ? $resolved
            : DistributionConfig::fromFile($this->projectRoot . '/config/anokii.yaml');
    }

    /**
     * The optional `chat:` block of config/anokii.yaml. Empty array when absent,
     * which yields the neutral defaults (treaty-wide vantage, package ChatVoice).
     *
     * @return array<string, mixed>
     */
    private function chatConfig(): array
    {
        $path = $this->projectRoot . '/config/anokii.yaml';
        if (!is_file($path)) {
            return [];
        }
        $parsed = Yaml::parseFile($path);
        $chat = is_array($parsed) ? ($parsed['chat'] ?? null) : null;

        return is_array($chat) ? $chat : [];
    }

    /**
     * @param array<string, mixed> $chat
     */
    private function chatVoice(array $chat): ChatVoice
    {
        $voice = is_array($chat['voice'] ?? null) ? $chat['voice'] : [];
        $intro = is_string($voice['intro'] ?? null) ? $voice['intro'] : null;
        $refusal = is_string($voice['refusal'] ?? null) ? $voice['refusal'] : null;
        $perCommunity = [];
        if (is_array($voice['community_refusals'] ?? null)) {
            foreach ($voice['community_refusals'] as $slug => $text) {
                if (is_string($slug) && is_string($text)) {
                    $perCommunity[$slug] = $text;
                }
            }
        }

        $default = new ChatVoice();

        return new ChatVoice(
            assistantIntro: $intro ?? $default->assistantIntro,
            defaultRefusal: $refusal ?? $default->defaultRefusal,
            communityRefusals: $perCommunity,
        );
    }

    /**
     * @param array<string, mixed> $chat
     *
     * @return list<string>
     */
    private function vantages(array $chat): array
    {
        $list = $chat['vantages'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn($v): string => is_string($v) ? strtolower(trim($v)) : '', $list), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @param array<string, mixed> $chat
     */
    private function defaultVantage(array $chat): string
    {
        $value = $chat['default_vantage'] ?? null;

        return is_string($value) ? strtolower(trim($value)) : '';
    }

    /**
     * Rate-limit max requests per window (chat.rate_limit.max_requests), default 12.
     *
     * @param array<string, mixed> $chat
     */
    private function rateMaxRequests(array $chat): int
    {
        $rl = is_array($chat['rate_limit'] ?? null) ? $chat['rate_limit'] : [];
        $v = (int) ($rl['max_requests'] ?? 0);

        return $v > 0 ? $v : 12;
    }

    /**
     * Rate-limit window length in seconds (chat.rate_limit.window_seconds), default 60.
     *
     * @param array<string, mixed> $chat
     */
    private function rateWindowSeconds(array $chat): int
    {
        $rl = is_array($chat['rate_limit'] ?? null) ? $chat['rate_limit'] : [];
        $v = (int) ($rl['window_seconds'] ?? 0);

        return $v > 0 ? $v : 60;
    }

    /**
     * A positive int from the chat config, or the given default when unset/invalid.
     *
     * @param array<string, mixed> $chat
     */
    private function positiveInt(array $chat, string $key, int $default): int
    {
        $v = (int) ($chat[$key] ?? 0);

        return $v > 0 ? $v : $default;
    }

    private function persistentDatabase(): DatabaseInterface
    {
        return $this->persistent ??= DBALDatabase::createSqlite($this->databasePath());
    }

    private function databasePath(): string
    {
        $configured = getenv('WAASEYAA_DB') ?: '';
        if ($configured === '') {
            return $this->projectRoot . '/storage/waaseyaa.sqlite';
        }
        $isAbsolute = str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;

        return $isAbsolute ? $configured : $this->projectRoot . '/' . ltrim($configured, './');
    }

    private function kernelPresent(): bool
    {
        try {
            return $this->resolve(DatabaseInterface::class) instanceof DatabaseInterface;
        } catch (\Throwable) {
            return false;
        }
    }
}
