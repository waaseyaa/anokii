<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Keyword retrieval over the Anokii relational graph (no embeddings).
 *
 * For a question asked from the vantage of community C, it:
 *   1. keyword-scores every doc_chunk by weighted term frequency (the relevance
 *      gate: only chunks whose text matches the question survive);
 *   2. resolves each surviving chunk's source entity (service/project, by slug)
 *      to a place, topic, and relationship to C, and drops anything outside C's
 *      catchment (own place, curated region, or a shared project related to C);
 *   3. infers the question's topic from {@see TopicVocabulary};
 *   4. ranks by topic match, then relationship closeness (own, then region or
 *      shared, then broader content), then straight-line proximity to C's
 *      centroid, with keyword score as the final tie-break.
 *
 * Each returned {@see Passage} carries its source URL/title and a human
 * relationship/location label so the answer can cite and locate every chunk.
 * General content (chunks with no source entity) stays answerable from any
 * vantage as "broader" context, which is also the treaty-wide default when no
 * community is selected. Reads straight from the persistent SQLite file via
 * DatabaseInterface (never an entity repo, which is ephemeral at route-build
 * time).
 *
 * Promoted from the oiatc app. A single-vantage sovereign install (one community,
 * no region) runs the same scorer and reduces to flat keyword retrieval.
 *
 * @api
 */
final class GraphRetriever implements RetrieverInterface
{
    private const CLOSE_OWN = 0;
    private const CLOSE_REGION = 1;
    private const CLOSE_BROADER = 2;

    /**
     * Relevance gate, so weak matches are not padded in and cited. A passage is
     * kept only if its keyword overlap is within SCORE_MARGIN of the strongest
     * match in the candidate set AND clears SCORE_FLOOR (a non-trivial overlap,
     * above a single weak body-term hit which scores 1.0). Gating on keyword
     * relevance (not closeness) means a weak broader page is dropped next to a
     * strong local answer, and a weak local match is dropped next to a strongly
     * relevant broader page; closeness only influences ordering, not inclusion.
     */
    private const SCORE_MARGIN = 0.5;
    private const SCORE_FLOOR = 1.5;

    /** The keyword-scoring strategy: defaults to {@see GraphScorer}. */
    private readonly ScorerInterface $scorer;

    /**
     * @param float $scoreMargin keep passages within this fraction of the top
     *                           keyword score (default {@see SCORE_MARGIN})
     * @param float $scoreFloor  minimum keyword score to keep (default
     *                           {@see SCORE_FLOOR}); a single-vantage sovereign
     *                           install can pass its own gate (e.g. 0.45 margin,
     *                           0.0 floor) without moving graph installs
     * @param bool  $flat        single-vantage flat mode: skip the topic-confidence
     *                           precision drop, so a no-graph install gets pure
     *                           keyword-gated retrieval. Scope resolution already
     *                           no-ops when the graph tables are absent. Default
     *                           false keeps graph-install behaviour unchanged.
     * @param ?ScorerInterface $scorer the keyword model; null keeps the default
     *                           {@see GraphScorer} (whole-token weighted term
     *                           frequency), so graph installs are unchanged. A
     *                           sovereign install passes {@see PrefixScorer} for
     *                           byte-identical word-prefix retrieval.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly TopicVocabulary $topics = new TopicVocabulary(),
        private readonly float $scoreMargin = self::SCORE_MARGIN,
        private readonly float $scoreFloor = self::SCORE_FLOOR,
        private readonly bool $flat = false,
        ?ScorerInterface $scorer = null,
    ) {
        $this->scorer = $scorer ?? new GraphScorer();
    }

    public function retrieve(string $query, string $community, int $k = 6): array
    {
        $terms = $this->scorer->terms($query);
        if ($terms === []) {
            return [];
        }

        $places = $this->loadPlaces();
        $communities = $this->loadCommunities();
        $services = $this->loadServices();
        $projects = $this->loadProjects();

        $vantage = $communities[$community] ?? null;
        $ownPlace = $vantage['place'] ?? '';
        $regionSet = [];
        foreach ($vantage['region'] ?? [] as $slug) {
            $regionSet[$slug] = true;
        }
        $centroid = ($ownPlace !== '' && isset($places[$ownPlace])) ? $places[$ownPlace] : null;
        $inferredTopic = $this->topics->infer($query);

        $scored = [];
        foreach ($this->loadChunks() as $chunk) {
            $kw = $this->scorer->score($terms, $chunk['title'], $chunk['heading'], $chunk['text']);
            if ($kw <= 0.0) {
                continue;
            }

            $scope = $this->resolveScope($chunk, $community, $ownPlace, $regionSet, $services, $projects, $places);
            if ($scope === null) {
                continue; // out of this community's catchment
            }

            $distance = ($centroid !== null && $scope['place'] !== '' && isset($places[$scope['place']]))
                ? $this->haversine($centroid['lat'], $centroid['lng'], $places[$scope['place']]['lat'], $places[$scope['place']]['lng'])
                : \PHP_FLOAT_MAX;

            $scored[] = [
                'passage' => new Passage(
                    sourceUrl: $chunk['source_url'],
                    title: $chunk['title'],
                    heading: $chunk['heading'],
                    text: $chunk['text'],
                    score: $kw,
                    relationship: $scope['relationship'],
                ),
                'topicMatch' => ($inferredTopic !== null && $scope['topic'] === $inferredTopic) ? 1 : 0,
                'closeness' => $scope['closeness'],
                'distance' => $distance,
                'kw' => $kw,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['topicMatch'] <=> $a['topicMatch']
                ?: ($a['closeness'] <=> $b['closeness'])
                ?: ($a['distance'] <=> $b['distance'])
                ?: ($b['kw'] <=> $a['kw']);
        });

        if ($scored === []) {
            return [];
        }

        // Relevance gate: keep only passages whose keyword overlap is within
        // SCORE_MARGIN of the strongest match in the candidate set and clears
        // SCORE_FLOOR, instead of padding to a fixed k. If nothing clears the
        // floor, return nothing so the controller refuses clearly. Ordering is
        // unchanged (topic, then closeness, then proximity), so the kept set
        // stays best-first.
        $maxKw = 0.0;
        foreach ($scored as $row) {
            $maxKw = max($maxKw, $row['kw']);
        }
        $threshold = max($maxKw * $this->scoreMargin, $this->scoreFloor);

        $kept = [];
        foreach ($scored as $row) {
            if ($row['kw'] >= $threshold) {
                $kept[] = $row;
            }
        }

        // Topic-confidence precision. Once the question has a confident on-topic
        // answer (the inferred topic is shared by at least one kept passage),
        // drop every passage that does not share that topic, from both the
        // grounding context and the Sources line. When there is no confident
        // topic match, the keyword-gated set is kept as-is so broader content
        // stays answerable.
        if (!$this->flat && $inferredTopic !== null) {
            $onTopic = array_values(array_filter($kept, static fn(array $row): bool => $row['topicMatch'] === 1));
            if ($onTopic !== []) {
                $kept = $onTopic;
            }
        }

        return array_map(static fn(array $row): Passage => $row['passage'], array_slice($kept, 0, max(1, $k)));
    }

    /**
     * Resolve a chunk's source entity to {place, topic, relationship label,
     * closeness}, or null when it falls outside the vantage community's reach.
     *
     * @param array{source_url: string, title: string, heading: string, text: string, entity_type: string, entity_id: string} $chunk
     * @param array<string, bool> $regionSet
     * @param array<string, array{name: string, place: string, topic: string}> $services
     * @param array<string, array{name: string, place: string, topic: string, relates: list<string>}> $projects
     * @param array<string, array{name: string, lat: float, lng: float, travel: string}> $places
     *
     * @return array{place: string, topic: string, relationship: string, closeness: int}|null
     */
    private function resolveScope(array $chunk, string $community, string $ownPlace, array $regionSet, array $services, array $projects, array $places): ?array
    {
        $type = $chunk['entity_type'];
        $id = $chunk['entity_id'];

        if ($type === 'service' && isset($services[$id])) {
            $place = $services[$id]['place'];
            $topic = $services[$id]['topic'];
            // A province-wide service (no single town, e.g. a helpline) has an
            // empty place. It is reachable from any vantage as broader content and
            // is labelled by its own name, never pinned to a town. Ranked after
            // own and region by closeness.
            if ($place === '') {
                return ['place' => '', 'topic' => $topic, 'relationship' => $services[$id]['name'], 'closeness' => self::CLOSE_BROADER];
            }
            if ($place === $ownPlace) {
                return ['place' => $place, 'topic' => $topic, 'relationship' => $this->placeName($places, $place), 'closeness' => self::CLOSE_OWN];
            }
            if (isset($regionSet[$place])) {
                return ['place' => $place, 'topic' => $topic, 'relationship' => $this->regionLabel($places, $place), 'closeness' => self::CLOSE_REGION];
            }

            return null; // service outside the catchment
        }

        if ($type === 'project' && isset($projects[$id])) {
            $project = $projects[$id];
            $place = $project['place'];
            $topic = $project['topic'];
            if (in_array($community, $project['relates'], true)) {
                return ['place' => $place, 'topic' => $topic, 'relationship' => $project['name'] . ' (shared project)', 'closeness' => self::CLOSE_OWN];
            }
            if (isset($regionSet[$place])) {
                return ['place' => $place, 'topic' => $topic, 'relationship' => $project['name'] . ' (region project)', 'closeness' => self::CLOSE_REGION];
            }

            return null;
        }

        // General content (no source entity): answerable from any vantage.
        return ['place' => '', 'topic' => '', 'relationship' => 'general', 'closeness' => self::CLOSE_BROADER];
    }

    /**
     * @param array<string, array{name: string, lat: float, lng: float, travel: string}> $places
     */
    private function placeName(array $places, string $slug): string
    {
        return $places[$slug]['name'] ?? $slug;
    }

    /**
     * @param array<string, array{name: string, lat: float, lng: float, travel: string}> $places
     */
    private function regionLabel(array $places, string $slug): string
    {
        $name = $this->placeName($places, $slug);
        $travel = $places[$slug]['travel'] ?? '';

        return $travel !== '' ? "{$name} (region, {$travel})" : "{$name} (region)";
    }

    /**
     * @return array<string, array{name: string, lat: float, lng: float, travel: string}>
     */
    private function loadPlaces(): array
    {
        $out = [];
        foreach ($this->rows('SELECT name, _data FROM place') as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$slug] = [
                'name' => $name,
                'lat' => (float) ($data['lat'] ?? 0),
                'lng' => (float) ($data['lng'] ?? 0),
                'travel' => (string) ($data['travel_note'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{name: string, place: string, region: list<string>}>
     */
    private function loadCommunities(): array
    {
        $out = [];
        foreach ($this->rows('SELECT name, _data FROM community') as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $region = is_array($data['region'] ?? null) ? $data['region'] : json_decode((string) ($data['region'] ?? ''), true);
            $out[$slug] = [
                'name' => $name,
                'place' => (string) ($data['located_at'] ?? ''),
                'region' => is_array($region) ? array_values(array_map(strval(...), $region)) : [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{name: string, place: string, topic: string}>
     */
    private function loadServices(): array
    {
        $out = [];
        foreach ($this->rows('SELECT name, _data FROM service') as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$slug] = [
                'name' => $name,
                'place' => (string) ($data['located_at'] ?? ''),
                'topic' => (string) ($data['has_topic'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{name: string, place: string, topic: string, relates: list<string>}>
     */
    private function loadProjects(): array
    {
        $out = [];
        foreach ($this->rows('SELECT name, _data FROM project') as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $relates = is_array($data['relates_to'] ?? null) ? $data['relates_to'] : json_decode((string) ($data['relates_to'] ?? ''), true);
            $out[$slug] = [
                'name' => $name,
                'place' => (string) ($data['located_at'] ?? ''),
                'topic' => (string) ($data['has_topic'] ?? ''),
                'relates' => is_array($relates) ? array_values(array_map(strval(...), $relates)) : [],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{source_url: string, title: string, heading: string, text: string, entity_type: string, entity_id: string}>
     */
    private function loadChunks(): array
    {
        $chunks = [];
        foreach ($this->rows('SELECT title, _data FROM doc_chunk') as [$title, $data]) {
            $chunks[] = [
                'source_url' => (string) ($data['source_url'] ?? ''),
                'title' => $title,
                'heading' => (string) ($data['heading'] ?? ''),
                'text' => (string) ($data['text'] ?? ''),
                'entity_type' => (string) ($data['entity_type'] ?? ''),
                'entity_id' => (string) ($data['entity_id'] ?? ''),
            ];
        }

        return $chunks;
    }

    /**
     * Run a `SELECT <labelColumn>, _data` query and yield [labelValue, decodedData]
     * pairs. A missing table (graph not seeded yet) yields nothing rather than
     * throwing, so a fresh install answers from general content (or refuses)
     * instead of erroring.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function rows(string $sql): array
    {
        $out = [];
        try {
            foreach ($this->db->query($sql) as $row) {
                $values = array_values($row);
                $label = (string) ($values[0] ?? '');
                $data = json_decode((string) ($row['_data'] ?? ''), true);
                if (is_array($data)) {
                    $out[] = [$label, $data];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
