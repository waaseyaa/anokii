<?php

declare(strict_types=1);

namespace Anokii\Controller;

use Anokii\CoIntelligence\ChatPromptBuilder;
use Anokii\CoIntelligence\ChatQueryLogInterface;
use Anokii\CoIntelligence\Passage;
use Anokii\CoIntelligence\RateLimiterInterface;
use Anokii\CoIntelligence\RetrieverInterface;
use Anokii\CoIntelligence\TopicVocabulary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * POST /api/chat: grounded, cited Q&A over the doc_chunk knowledge base, for the
 * public graph-chat surface (shared-graph tier).
 *
 * Path B (retrieve then prompt): keyword-retrieve the top passages for the
 * vantage community, hand them to the model with a grounded/cited system prompt,
 * and stream the answer back as SSE. Off-corpus questions and the not-configured
 * case short-circuit to a deterministic message and never call the model.
 * Rate-limited per client. The no-PII query log records the question, outcome,
 * topic, and cited source URLs only.
 *
 * The vantage list, default vantage, and model id are install configuration,
 * passed in by the service provider; the engine and contract are the package's.
 *
 * @api
 */
final class PublicChatController
{
    private const TOP_K = 6;
    private const MAX_QUESTION_CHARS = 500;
    private const MAX_TOKENS = 700;

    /** Higher ceiling when web research is on: room for a cited web section. */
    private const MAX_TOKENS_WEB = 1100;

    /**
     * Anthropic's server-side web search tool. Capped so a single question can't
     * fan out into an unbounded (and billed) crawl. Only attached when web
     * research is enabled for this instance.
     */
    private const WEB_SEARCH_TOOL = [
        'type' => 'web_search_20250305',
        'name' => 'web_search',
        'max_uses' => 4,
    ];

    /**
     * @param list<string> $communities known vantage community slugs
     */
    public function __construct(
        private readonly RetrieverInterface $retriever,
        private readonly ChatPromptBuilder $prompts,
        private readonly ProviderInterface $provider,
        private readonly RateLimiterInterface $limiter,
        private readonly LoggerInterface $logger,
        private readonly ChatQueryLogInterface $queryLog,
        private readonly TopicVocabulary $topics,
        private readonly bool $configured,
        private readonly string $model,
        private readonly array $communities = [],
        private readonly string $defaultCommunity = '',
        // When true (and configured), the model may call a web_search tool to add
        // current detail on the question's topic. Passages stay primary; web
        // findings are presented separately. Off by default so closed-corpus is
        // the safe fallback when the instance hasn't opted in.
        private readonly bool $webResearch = false,
    ) {}

    public function handle(Request $request): Response
    {
        $retryAfter = $this->limiter->retryAfter($this->clientKey($request));
        if ($retryAfter !== null) {
            return new JsonResponse(
                ['error' => 'Too many requests. Please wait a moment.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $question = $this->readQuestion($request);
        if ($question === null) {
            return new JsonResponse(['error' => 'Provide a non-empty "question" (max 500 characters).'], Response::HTTP_BAD_REQUEST);
        }

        // Vantage community: a point of view onto the shared graph, not a tenant.
        $community = $this->readCommunity($request);
        // Inferred topic of the question, recorded for content-gap mining (the
        // same vocabulary the retriever ranks by). Null when nothing matches.
        $topic = $this->topics->infer($question);

        if (!$this->configured) {
            $this->queryLog->record($community, $question, 'unavailable', $topic, []);

            return $this->streamMessageText($this->prompts->noAnswerFor($community), []);
        }

        $passages = $this->retriever->retrieve($question, $community, self::TOP_K);
        if ($passages === []) {
            // Nothing in this community's reach. With web research enabled we
            // still answer questions that map to an allowed topic, letting the
            // model research them on the web (the topic vocabulary stays the
            // guardrail, so off-topic questions never trigger a search).
            // Otherwise it is a deterministic refusal with no model call.
            if (!$this->webResearch || $topic === null) {
                $this->queryLog->record($community, $question, 'no_match', $topic, []);

                return $this->streamMessageText($this->prompts->noAnswerFor($community), []);
            }
        }

        return $this->streamAnswer($question, $community, $topic, $passages);
    }

    /**
     * @param list<Passage> $passages
     */
    private function streamAnswer(string $question, string $community, ?string $topic, array $passages): StreamedResponse
    {
        $messageRequest = new MessageRequest(
            messages: [['role' => 'user', 'content' => $this->prompts->userMessage($question, $passages)]],
            system: $this->prompts->system($community, $this->webResearch),
            tools: $this->webResearch ? [self::WEB_SEARCH_TOOL] : [],
            maxTokens: $this->webResearch ? self::MAX_TOKENS_WEB : self::MAX_TOKENS,
        );
        $sources = $this->sources($passages);
        $sourceUrls = array_map(static fn(array $s): string => $s['source_url'], $sources);
        $provider = $this->provider;
        $logger = $this->logger;
        $queryLog = $this->queryLog;
        $noAnswer = $this->prompts->noAnswerFor($community);
        $webResearch = $this->webResearch;
        $model = $this->model;

        return $this->sse(static function () use ($provider, $messageRequest, $sources, $sourceUrls, $logger, $queryLog, $question, $community, $topic, $noAnswer, $webResearch, $model): void {
            $answer = '';
            try {
                if ($provider instanceof StreamingProviderInterface) {
                    $response = $provider->streamMessage($messageRequest, static function (StreamChunk $chunk) use (&$answer): void {
                        if ($chunk->type === 'text_delta' && $chunk->text !== '') {
                            // Strip em/en dashes server-side so a stray dash never
                            // ships even if the model ignores the prompt rule.
                            $clean = ChatPromptBuilder::sanitizeDashes($chunk->text);
                            $answer .= $clean;
                            self::emit('delta', ['text' => $clean]);
                        }
                    });
                } else {
                    // Provider can't stream: send once and emit the whole answer.
                    $response = $provider->sendMessage($messageRequest);
                    $answer = ChatPromptBuilder::sanitizeDashes($response->getText());
                    self::emit('delta', ['text' => $answer]);
                }
                self::emit('done', ['sources' => $sources]);

                // Outcome for content-gap mining: the model is told to reply with
                // the exact refusal text when the passages don't cover the
                // question, so an answer equal to it is "refused" (passages
                // existed but did not answer), distinct from "no_match".
                $outcome = trim($answer) === trim($noAnswer) ? 'refused' : 'answered';
                $queryLog->record($community, $question, $outcome, $topic, $sourceUrls);

                $usage = $response->usage + ['input_tokens' => 0, 'output_tokens' => 0];
                // No PII: only the question and the chunks used (cited source
                // URLs) are recorded, plus the vantage, outcome, topic, tokens.
                $logger->info('chat.llm.completed', [
                    'model' => $model,
                    'community' => $community,
                    'question' => $question,
                    'outcome' => $outcome,
                    'topic' => $topic ?? 'none',
                    'web_research' => $webResearch,
                    'input_tokens' => $usage['input_tokens'],
                    'output_tokens' => $usage['output_tokens'],
                    'stop_reason' => $response->stopReason,
                    'chunks_used' => $sourceUrls,
                ]);
            } catch (\Throwable $e) {
                $logger->error('chat.llm.failed', ['error' => $e->getMessage(), 'community' => $community]);
                $queryLog->record($community, $question, 'error', $topic, []);
                self::emit('delta', ['text' => $noAnswer]);
                self::emit('done', ['sources' => []]);
            }
        });
    }

    /**
     * Stream a fixed message as a single delta then done (used for refusals and
     * the not-configured case, so the widget handles every reply uniformly).
     *
     * @param list<array{title: string, source_url: string}> $sources
     */
    private function streamMessageText(string $message, array $sources): StreamedResponse
    {
        return $this->sse(static function () use ($message, $sources): void {
            self::emit('delta', ['text' => $message]);
            self::emit('done', ['sources' => $sources]);
        });
    }

    private function sse(callable $body): StreamedResponse
    {
        return new StreamedResponse(static function () use ($body): void {
            $body();
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function emit(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * @param list<Passage> $passages
     *
     * @return list<array{title: string, source_url: string}>
     */
    private function sources(array $passages): array
    {
        $seen = [];
        $out = [];
        foreach ($passages as $p) {
            if (isset($seen[$p->sourceUrl])) {
                continue;
            }
            $seen[$p->sourceUrl] = true;
            $out[] = ['title' => $p->title, 'source_url' => $p->sourceUrl];
        }

        return $out;
    }

    private function readQuestion(Request $request): ?string
    {
        $payload = json_decode((string) $request->getContent(), true);
        $question = is_array($payload) ? ($payload['question'] ?? null) : null;
        if (!is_string($question)) {
            return null;
        }
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return null;
        }

        return $question;
    }

    /**
     * The vantage community slug from the request body. Optional and defaults to
     * the install's default vantage; an unrecognized value also falls back to it
     * rather than erroring, so a stale client can't break the endpoint.
     */
    private function readCommunity(Request $request): string
    {
        $payload = json_decode((string) $request->getContent(), true);
        $value = is_array($payload) ? ($payload['community'] ?? null) : null;
        $slug = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($slug, $this->communities, true) ? $slug : $this->defaultCommunity;
    }

    private function clientKey(Request $request): string
    {
        // Behind Cloudflare + cloudflared, the real client is in CF-Connecting-IP;
        // fall back to the first X-Forwarded-For hop, then the socket address.
        $cf = $request->headers->get('CF-Connecting-IP');
        if (is_string($cf) && $cf !== '') {
            return $cf;
        }
        $xff = $request->headers->get('X-Forwarded-For');
        if (is_string($xff) && $xff !== '') {
            return trim(explode(',', $xff)[0]);
        }

        return (string) $request->getClientIp();
    }
}
