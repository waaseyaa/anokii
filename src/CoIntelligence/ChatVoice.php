<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * The install-specific voice for the grounded chat: the one-line assistant
 * identity used in the system prompt, the default refusal text, and any
 * per-vantage refusal overrides.
 *
 * This is the reconciliation that de-hardcodes the prompt: oiatc carried its
 * OIATC/Sagamok/Massey strings inside the prompt builder. The canonical builder
 * takes a ChatVoice instead, so each install (oiatc, rhtcircle, a sovereign
 * Nation) supplies its own identity and refusal without forking the engine. A
 * neutral default keeps the package working out of the box.
 *
 * The refusal text is the exact string the model is told to return verbatim when
 * the passages do not answer the question, so the controller can detect a
 * refusal by string match.
 *
 * @api
 */
final readonly class ChatVoice
{
    /**
     * @param string                $assistantIntro one or two plain sentences naming who the assistant is and what the install publishes
     * @param string                $defaultRefusal the exact refusal text, returned verbatim when nothing supports an answer
     * @param array<string, string> $communityRefusals optional per-vantage refusal overrides, keyed by community slug
     */
    public function __construct(
        public string $assistantIntro = 'You are the Anokii community assistant. This install publishes plain-language, public community resources.',
        public string $defaultRefusal = "I don't know that from the published content here. For this, please contact the relevant community office, or use the official directory. For emergencies, call 911.",
        public array $communityRefusals = [],
    ) {}

    public function refusalFor(string $community): string
    {
        return $this->communityRefusals[$community] ?? $this->defaultRefusal;
    }
}
