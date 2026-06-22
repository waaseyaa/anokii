<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * Builds the grounded, cited system prompt and the user message for the chat
 * endpoint. Pure and deterministic so the prompt contract can be tested.
 *
 * The model is told to answer ONLY from the supplied passages, cite the page it
 * used, refuse with the exact configured refusal when the passages don't cover
 * the question, never invent contacts or links, and never collect personal
 * information. The install's identity and refusal come from {@see ChatVoice}, so
 * the contract is shared across installs without hardcoding any one brand.
 *
 * @api
 */
final class ChatPromptBuilder
{
    public function __construct(private readonly ChatVoice $voice = new ChatVoice()) {}

    public function system(string $community = '', bool $webResearch = false): string
    {
        $noAnswer = $this->noAnswerFor($community);
        $intro = $this->voice->assistantIntro;

        if ($webResearch) {
            return $this->systemWithWebResearch($intro, $noAnswer);
        }

        return <<<PROMPT
            {$intro} You answer questions using ONLY the numbered passages provided in the user's message.

            Each passage carries a "location" describing how its resource relates to the community being asked from: the community's own place, a place in its surrounding region, or a shared project. Resources can come from the surrounding region, that is expected.

            Rules:
            - Answer ONLY from the passages. Do not use outside knowledge.
            - If the passages do not contain the answer, reply exactly: "{$noAnswer}" Do not guess.
            - When a resource sits in the surrounding region or is a shared project rather than in the community itself, say so plainly using its location.
            - Cite the page you used at the end of each relevant point, as "(source: <title>, <source_url>)". Use only source_url and title values that appear in the passages.
            - Never invent phone numbers, names, emails, links, programs, distances, or travel times. If a contact is not in the passages, do not state one.
            - Do not ask for, collect, or store any personal information. If a question needs the user's personal details, tell them to contact their community office directly instead.
            - Keep answers short and plain.
            - Never use em dashes or en dashes. Use commas, periods, or parentheses instead.
            - Do not add a disclaimer, affiliation note, or "general information / not legal advice" caveat. The page already shows one below your answer. Stop once the question is answered.
            - For emergencies, tell the user to call 911.
            PROMPT;
    }

    /**
     * The web-research variant: the install's own passages stay primary and
     * authoritative; the model may additionally use a web_search tool to add
     * current or supplementary detail on the same community-services topic, kept
     * in a clearly separated "From the wider web:" section. The grounding and
     * safety rules (no invented contacts, no PII, no dashes, 911) are unchanged;
     * only the closed-corpus restriction is relaxed. Off by default.
     */
    private function systemWithWebResearch(string $intro, string $noAnswer): string
    {
        return <<<PROMPT
            {$intro} You answer questions for community members about local services, programs, and benefits.

            You have two sources of information:
            1. The numbered passages in the user's message. These come from this install's own published pages and are the authoritative source for its positions, the specific services it has gathered, and the contacts on those pages. Lead with them.
            2. A web_search tool. You may use it to add current or practical detail on the SAME community-services topic as the question (for example eligibility, hours, application steps, or a relevant service the passages do not cover). Stay on that topic. Do not research unrelated subjects.

            Each passage carries a "location" describing how its resource relates to the community being asked from: the community's own place, a place in its surrounding region, or a shared project. Resources can come from the surrounding region, that is expected.

            Rules:
            - Lead with what the passages say, and cite each point you take from them as "(source: <title>, <source_url>)". Use only source_url and title values that appear in the passages. When a resource sits in the surrounding region or is a shared project rather than in the community itself, say so plainly using its location.
            - When you add anything found on the web, put it in a separate section that begins exactly with "From the wider web:" on its own line. Keep web facts out of the cited part above, and give the link for each web fact inline as [page name](https url).
            - Prefer trustworthy sources: government (ontario.ca, canada.ca, municipal), public health units, and First Nation or Indigenous organization sites. Do not rely on forums, social media, or unverified blogs.
            - Never invent phone numbers, names, emails, links, programs, distances, or travel times. State a contact only if it appears in a passage or on a web page you actually found, and cite it to that source.
            - If you cannot find a reliable answer in the passages or on the web, reply exactly: "{$noAnswer}" Do not guess.
            - Do not ask for, collect, or store any personal information. If a question needs the user's personal details, tell them to contact their community office directly instead.
            - Keep answers short and plain.
            - Never use em dashes or en dashes. Use commas, periods, or parentheses instead.
            - Do not add a disclaimer, affiliation note, or "general information / not legal advice" caveat. The page already shows one below your answer.
            - For emergencies, tell the user to call 911.
            PROMPT;
    }

    /**
     * @param list<Passage> $passages
     */
    public function userMessage(string $question, array $passages): string
    {
        $blocks = [];
        foreach ($passages as $i => $p) {
            $n = $i + 1;
            $location = $p->relationship !== '' ? $p->relationship : 'general';
            $blocks[] = "[Passage {$n}] title: {$p->title} | heading: {$p->heading} | location: {$location} | source_url: {$p->sourceUrl}\n{$p->text}";
        }
        $context = $blocks === [] ? '(no passages found)' : implode("\n\n", $blocks);

        return "Question: {$question}\n\nPassages:\n{$context}";
    }

    /**
     * The exact refusal text for a vantage community, from the configured voice.
     */
    public function noAnswerFor(string $community): string
    {
        return $this->voice->refusalFor($community);
    }

    /**
     * Deterministically strip em dashes (U+2014) and en dashes (U+2013) from
     * model text before it ships, so a stray dash never reaches the browser even
     * if the model ignores the system-prompt rule. An em dash (clause separator)
     * collapses with its surrounding spaces into a comma; an en dash (usually a
     * range) becomes a plain hyphen so "9-5" stays readable. Newlines are left
     * intact so the client-side markdown render is unaffected. Pure/testable.
     */
    public static function sanitizeDashes(string $text): string
    {
        // Em dash, with any surrounding spaces/tabs (not newlines), becomes ", ".
        $text = preg_replace('/[ \t]*\x{2014}[ \t]*/u', ', ', $text) ?? $text;

        // En dash becomes a hyphen-minus (keeps numeric ranges readable).
        return str_replace("\u{2013}", '-', $text);
    }
}
