<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * The canonical Anokii topic vocabulary and the keyword inference used to map a
 * question (and a doc_chunk) to a topic slug.
 *
 * This is the single source of truth shared by the seeder (which creates Topic
 * entities and tags Services/Projects) and the retriever (which infers the
 * question's topic so topic match can drive ranking). Keyword matching only; no
 * embeddings. An install can extend the default set in a later increment; the
 * defaults cover the community-services topics common across the deployments.
 *
 * @api
 */
final class TopicVocabulary
{
    /**
     * slug => [name, keywords]. Multi-word keywords are matched as substrings of
     * the lower-cased question; single words are matched as whole tokens.
     *
     * @var array<string, array{name: string, keywords: list<string>}>
     */
    private const TOPICS = [
        'housing' => [
            'name' => 'Housing',
            'keywords' => ['housing', 'house', 'home', 'rent', 'rental', 'rent-to-own', 'landlord', 'eviction', 'repair', 'maintenance', 'renovation', 'mortgage', 'cmhc', 'waiting list', 'shelter', 'homeless', 'homelessness', 'social housing', 'community housing', 'rent-geared-to-income'],
        ],
        'employment-training' => [
            'name' => 'Jobs and training',
            'keywords' => ['job', 'jobs', 'employment', 'work', 'training', 'career', 'hire', 'hiring', 'resume', 'apprentice', 'apprenticeship', 'lifelong learning', 'self-employment', 'upgrading', 'jobs and training', 'skills'],
        ],
        'business' => [
            'name' => 'Business and economic development',
            'keywords' => ['business', 'entrepreneur', 'entrepreneurial', 'startup', 'start-up', 'economic development', 'enterprise', 'company', 'loan', 'sdc'],
        ],
        'primary-health' => [
            'name' => 'Primary health',
            'keywords' => ['health', 'primary care', 'primary health', 'doctor', 'physician', 'nurse', 'nurse practitioner', 'clinic', 'medical', 'health centre', 'health center', 'health access', 'health team', 'immunization', 'immunisation', 'vaccine', 'vaccination', 'public health', 'diabetes', 'foot care', 'prenatal'],
        ],
        'mental-health-addictions' => [
            'name' => 'Mental health and addictions',
            'keywords' => ['mental', 'mental health', 'addictions', 'addiction', 'counselling', 'counseling', 'crisis', 'wellness', 'wellbeing', 'well-being', 'substance', 'substance use', 'detox', 'withdrawal', 'suicide', 'trauma', 'healing', 'talk4healing', 'overdose', 'mental wellness'],
        ],
        'child-and-family' => [
            'name' => 'Child and family',
            'keywords' => ['child', 'children', 'childcare', 'child care', 'daycare', 'day care', 'family', 'families', 'parenting', 'parent', 'earlyon', 'early years', 'early on', 'infant', 'toddler', 'kids', 'child welfare', 'foster', 'nogdawindamin'],
        ],
        'income-support' => [
            'name' => 'Income support',
            'keywords' => ['ontario works', 'income support', 'social assistance', 'welfare', 'odsp', 'financial assistance', 'emergency assistance', 'social services', 'benefits'],
        ],
        'food-security' => [
            'name' => 'Food security',
            'keywords' => ['food', 'food bank', 'foodbank', 'food security', 'hunger', 'meal', 'meals', 'grocery', 'groceries', 'good food box', 'nutrition'],
        ],
        'transportation' => [
            'name' => 'Transportation',
            'keywords' => ['transportation', 'transit', 'bus', 'ride', 'rides', 'transport', 'driver', 'medical transportation', 'travel'],
        ],
        'legal-aid' => [
            'name' => 'Legal aid',
            'keywords' => ['legal', 'legal aid', 'lawyer', 'court', 'justice', 'gladue', 'duty counsel'],
        ],
        'education-youth' => [
            'name' => 'Education and youth',
            'keywords' => ['school', 'student', 'youth', 'scholarship', 'tuition', 'post-secondary', 'education', 'college', 'university', 'classroom'],
        ],
        'finance' => [
            'name' => 'Finance and per capita',
            'keywords' => ['per capita', 'per-capita', 'finance', 'deposit', 'pre-authorized', 'payment', 'cheque', 'payout', 'distribution', 'banking'],
        ],
        'membership-status' => [
            'name' => 'Membership and status',
            'keywords' => ['membership', 'member', 'status card', 'status', 'register', 'registration', 'band number', 'citizenship', 'enrolment', 'enrollment'],
        ],
        'lands-environment' => [
            'name' => 'Lands and environment',
            'keywords' => ['land', 'lands', 'hunting', 'fishing', 'harvest', 'environment', 'territory', 'trapping', 'water', 'lre'],
        ],
        'treaty' => [
            'name' => 'Treaty and settlement',
            'keywords' => ['treaty', 'annuity', 'augmentation', 'restoule', 'settlement', 'robinson huron', 'litigation fund', 'rhtlf', 'beneficiary', 'four dollars'],
        ],
        'community-safety' => [
            'name' => 'Community safety',
            'keywords' => ['safety', 'hate', 'extremism', 'racism', 'harassment', 'intimidation', 'second sons', 'white nationalist', 'mmiwg', 'disinformation'],
        ],
        'energy-solar' => [
            'name' => 'Energy and solar',
            'keywords' => ['solar', 'energy', 'battery', 'bess', 'panel', 'electricity', 'renewable', 'grid', 'megawatt', 'ieso', 'iesos', 'massey solar', 'storage', 'wind'],
        ],
        // Commerce and everyday-life layer, added for the territory resource seed
        // (Highway 17 / North Shore corridor). Appended after the social-service
        // topics so a keyword tie resolves to the earlier-declared topic and the
        // existing inference is unchanged; a new topic only wins when its own
        // keywords give it strictly more distinct hits.
        'groceries-and-food' => [
            'name' => 'Groceries and food',
            'keywords' => ['grocery', 'groceries', 'grocery store', 'supermarket', 'butcher', 'bakery', 'baked goods', 'produce', 'abattoir', 'deli', 'general store', 'convenience store', 'corner store', 'variety store', 'fresh food', 'meat'],
        ],
        'banking' => [
            'name' => 'Banking',
            'keywords' => ['bank', 'banks', 'atm', 'credit union', 'teller', 'chequing', 'savings account', 'cash machine', 'debit', 'rbc', 'scotiabank', 'cibc', 'bmo', 'td canada trust', 'northern credit union'],
        ],
        'dining' => [
            'name' => 'Dining',
            'keywords' => ['restaurant', 'restaurants', 'cafe', 'coffee', 'coffee shop', 'diner', 'pizza', 'takeout', 'take out', 'dine', 'dining', 'pub', 'eatery', 'lunch', 'breakfast'],
        ],
        'retail-and-hardware' => [
            'name' => 'Retail and hardware',
            'keywords' => ['hardware', 'hardware store', 'building supply', 'building materials', 'lumber', 'home hardware', 'rona', 'canadian tire', 'dollar store', 'thrift', 'thrift store', 'tools', 'retail', 'building centre', 'hardware dealer'],
        ],
        'everyday-services' => [
            'name' => 'Everyday services',
            'keywords' => ['post office', 'postal', 'canada post', 'library', 'pharmacy', 'drugstore', 'laundromat', 'laundry', 'gas station', 'gas', 'fuel', 'wifi', 'showers', 'marina'],
        ],
        'government-services' => [
            'name' => 'Government services',
            'keywords' => ['service canada', 'service ontario', 'serviceontario', 'health card', 'renew', 'renewal', 'passport', 'drivers licence', 'driver licence', 'licence', 'license', 'sin', 'social insurance number', 'birth certificate', 'ohip', 'town hall', 'city hall', 'municipal office', 'service centre', 'government'],
        ],
    ];

    /**
     * @return array<string, array{name: string, keywords: list<string>}>
     */
    public function all(): array
    {
        return self::TOPICS;
    }

    /**
     * Infer the best-matching topic slug for a piece of text, or null when no
     * topic's keywords appear. The topic with the most distinct keyword hits
     * wins; ties resolve to the earliest-declared topic for determinism.
     */
    public function infer(string $text): ?string
    {
        $haystack = ' ' . strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $text) ?? '') . ' ';

        $best = null;
        $bestScore = 0;
        foreach (self::TOPICS as $slug => $topic) {
            $hits = 0;
            foreach ($topic['keywords'] as $keyword) {
                if (str_contains($keyword, ' ')) {
                    if (str_contains($haystack, ' ' . $keyword . ' ') || str_contains($haystack, ' ' . $keyword)) {
                        $hits++;
                    }
                } elseif (str_contains($haystack, ' ' . $keyword . ' ')) {
                    $hits++;
                }
            }
            if ($hits > $bestScore) {
                $bestScore = $hits;
                $best = $slug;
            }
        }

        return $best;
    }
}
