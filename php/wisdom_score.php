<?php
/**
 * wisdom_score.php  (v3)
 *
 * Wisdom Score 𝓦 — relational extension of harmonic completeness 𝓗.
 * See docs/wisdom-score-design.md for the design rationale.
 *
 * v2 (2026-05-07): added two positive components — axis_explicit and
 * subordinating_synthesis — and rebalanced weights so that section-header
 * listing no longer games coverage/entropy.
 *
 * v3 (2026-05-17, agenda B.5): integrated mereological_coverage 𝓜 as
 * 8th component with weight 0.15; redistributed −5pp each from
 * synthesis_anchoring (0.15→0.10), axis_explicit (0.15→0.10),
 * subordinating_synthesis (0.20→0.15). Formula:
 *   𝓦 = 0.05·cov + 0.05·ent + 0.20·pair + 0.20·tens + 0.10·syn
 *      + 0.10·axis_explicit + 0.15·subordinating_synthesis
 *      + 0.15·mereological_coverage
 *
 * Public function:
 *   wisdomScore(string $response_text, array $whitelist,
 *               float $mereological_coverage = 0.0): array
 */

if (!function_exists('mg_wisdom_extract_codes')) {

function mg_wisdom_quadrants() {
    return ['PLA', 'MON', 'SUB', 'OBJ', 'TEO', 'PRA', 'FEN', 'NOU'];
}

function mg_wisdom_dialectical_axes() {
    return [
        ['OBJ', 'SUB'],
        ['TEO', 'PRA'],
        ['NOU', 'FEN'],
        ['PLA', 'MON'],
    ];
}

// Tension markers (multilingual). Keys are markers, values are language codes (informational).
function mg_wisdom_tension_markers() {
    return [
        // English
        'yet'              => 'en',
        'however'          => 'en',
        'but'              => 'en',
        'while'            => 'en',
        'although'         => 'en',
        'in tension with'  => 'en',
        'despite'          => 'en',
        'contrary to'      => 'en',
        'on the other hand'=> 'en',
        'rather than'      => 'en',
        'whereas'          => 'en',
        'nonetheless'      => 'en',
        'still'            => 'en',
        // Catalan
        'però'             => 'ca',
        'tot i que'        => 'ca',
        'tanmateix'        => 'ca',
        'no obstant'       => 'ca',
        'en canvi'         => 'ca',
        'mentre que'       => 'ca',
        "d'altra banda"    => 'ca',
        'al contrari'      => 'ca',
        'ans'              => 'ca',
        // (Spanish dropped 2026-05-08 per project decision — CA + EN only.)
    ];
}

// Mediator anchoring requirements per cicle.
// Each mediator must reference these cardinals in the same paragraph.
// NB: internal keys ('volta' => ...) preserved per Fase B (estructural rename).
function mg_wisdom_mediator_anchoring() {
    return [
        // Cicle de l'Aplicació
        'ANA' => ['volta' => 'aplicacio', 'requires' => ['FEN']],
        'SIN' => ['volta' => 'aplicacio', 'requires' => ['TEO', '__one_other__']],
        'AMO' => ['volta' => 'aplicacio', 'requires' => ['NOU', 'PRA']],
        'EXP' => ['volta' => 'aplicacio', 'requires' => ['PRA', 'FEN']],
        // Cicle de l'Orientació
        'STM' => ['volta' => 'orientacio', 'requires' => ['SUB', 'PRA']],
        'STT' => ['volta' => 'orientacio', 'requires' => ['SUB', 'TEO']],
        'SGT' => ['volta' => 'orientacio', 'requires' => ['TEO', 'OBJ']],
        'SGE' => ['volta' => 'orientacio', 'requires' => ['OBJ', 'PRA']],
        // Cicle del Coneixement
        'ART' => ['volta' => 'coneixement', 'requires' => ['FEN', 'SUB']],
        'MTP' => ['volta' => 'coneixement', 'requires' => ['SUB', 'NOU']],
        'MTF' => ['volta' => 'coneixement', 'requires' => ['NOU', 'OBJ']],
        'CIE' => ['volta' => 'coneixement', 'requires' => ['OBJ', 'FEN']],
    ];
}

/**
 * Extract canonical-looking codes from text (filtered against whitelist if given).
 */
function mg_wisdom_extract_codes($text, $whitelist = null) {
    $codes = [];
    if (preg_match_all('/\b[A-Z]{2,4}[0-9]?\b/u', $text, $m)) {
        foreach ($m[0] as $c) {
            if ($whitelist === null || isset($whitelist[$c])) {
                $codes[$c] = true;
            }
        }
    }
    return array_keys($codes);
}

/**
 * Split text into paragraphs (double-newline-delimited, with markdown bold sections kept).
 */
function mg_wisdom_paragraphs($text) {
    $paras = preg_split('/\n\s*\n+/u', trim($text));
    return $paras !== false ? $paras : [$text];
}

/**
 * For each paragraph, return the set of cited canonical codes (filtered against whitelist).
 */
function mg_wisdom_codes_per_paragraph($text, $whitelist) {
    $paras = mg_wisdom_paragraphs($text);
    $out = [];
    foreach ($paras as $p) {
        $codes = mg_wisdom_extract_codes($p, $whitelist);
        $out[] = ['text' => $p, 'codes' => $codes];
    }
    return $out;
}

/**
 * Compute coverage and entropy components (same as verifier.php).
 */
function mg_wisdom_coverage_entropy($cited_codes, $whitelist) {
    $counts = array_fill_keys(mg_wisdom_quadrants(), 0);
    foreach ($cited_codes as $c) {
        if (isset($whitelist[$c])) $counts[$whitelist[$c]] += 1;
    }
    $total = array_sum($counts);
    $touched = [];
    foreach (mg_wisdom_quadrants() as $q) if ($counts[$q] > 0) $touched[] = $q;
    $n = count($touched);
    $entropy = 0.0;
    if ($total > 0) {
        foreach (mg_wisdom_quadrants() as $q) {
            if ($counts[$q] > 0) {
                $p = $counts[$q] / $total;
                $entropy -= $p * log($p);
            }
        }
    }
    $norm_entropy = $total > 0 ? $entropy / log(8) : 0.0;
    return [
        'coverage' => $n / 8,
        'entropy_normalized' => $norm_entropy,
        'cited_quadrants' => $touched,
    ];
}

/**
 * dialectical_pair_density: for each of the 4 polar axes, score 1 if both poles
 * have at least one canonical-code citation IN THE SAME PARAGRAPH. Paragraph
 * breaks are author-intentional structural separators — co-occurrence across
 * paragraph breaks is not dialectical pairing, it is sequential listing.
 */
function mg_wisdom_dialectical_pairs($text, $whitelist) {
    $paras = mg_wisdom_codes_per_paragraph($text, $whitelist);
    $para_quadrants = [];
    foreach ($paras as $idx => $p) {
        $qs = [];
        foreach ($p['codes'] as $c) {
            if (isset($whitelist[$c])) $qs[$whitelist[$c]] = true;
        }
        $para_quadrants[$idx] = array_keys($qs);
    }

    $paired_axes = [];
    foreach (mg_wisdom_dialectical_axes() as $axis) {
        list($a, $b) = $axis;
        $paired = false;
        foreach ($para_quadrants as $qs) {
            if (in_array($a, $qs) && in_array($b, $qs)) { $paired = true; break; }
        }
        if ($paired) $paired_axes[] = $axis;
    }
    return [
        'dialectical_pair_density' => count($paired_axes) / 4.0,
        'paired_axes' => $paired_axes,
    ];
}

/**
 * tension_density: count tension markers within ±80 chars of two distinct
 * quadrant-mapped codes (codes belonging to DIFFERENT quadrants).
 * Saturates at 4 events.
 */
function mg_wisdom_tension($text, $whitelist) {
    $events = [];
    $text_lower = mb_strtolower($text);
    // Build position list of all canonical-code mentions and their quadrants
    $code_pos = [];
    if (preg_match_all('/\b[A-Z]{2,4}[0-9]?\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $code = $hit[0];
            if (isset($whitelist[$code])) {
                $code_pos[] = ['code' => $code, 'pos' => $hit[1], 'quad' => $whitelist[$code]];
            }
        }
    }
    if (count($code_pos) < 2) {
        return ['tension_density' => 0.0, 'tension_events' => []];
    }

    foreach (mg_wisdom_tension_markers() as $marker => $lang) {
        $marker_lower = mb_strtolower($marker);
        $offset = 0;
        while (($mp = mb_strpos($text_lower, $marker_lower, $offset)) !== false) {
            // Find codes within ±80 chars of this marker
            $nearby = array_filter($code_pos, function($c) use ($mp) {
                return abs($c['pos'] - $mp) <= 80;
            });
            // Need at least 2 codes from DIFFERENT quadrants
            $quads = array_unique(array_column($nearby, 'quad'));
            if (count($quads) >= 2) {
                $events[] = [
                    'marker' => $marker,
                    'codes' => array_values(array_unique(array_column($nearby, 'code'))),
                    'quadrants' => array_values($quads),
                ];
            }
            $offset = $mp + strlen($marker);
        }
    }
    $density = min(1.0, count($events) / 4.0);
    return ['tension_density' => $density, 'tension_events' => $events];
}

/**
 * synthesis_anchoring: for each present mediator code, check that its required
 * input cardinals are referenced in the same paragraph. Average over present
 * mediators. Returns 0 if no mediator is present.
 */
function mg_wisdom_synthesis($text, $whitelist) {
    $paras = mg_wisdom_codes_per_paragraph($text, $whitelist);
    $mediator_status = [];
    $voltes_active = [];

    foreach (mg_wisdom_mediator_anchoring() as $med => $spec) {
        // Find which paragraph(s) the mediator appears in
        $present = false;
        $anchored = false;
        foreach ($paras as $p) {
            if (!in_array($med, $p['codes'], true)) continue;
            $present = true;
            $reqs = $spec['requires'];
            $needs_one_other = in_array('__one_other__', $reqs, true);
            $req_concrete = array_filter($reqs, function($r) { return $r !== '__one_other__'; });
            // Check each concrete required cardinal is in this paragraph's codes
            $all_req_present = true;
            foreach ($req_concrete as $r) {
                if (!in_array($r, $p['codes'], true)) {
                    $all_req_present = false;
                    break;
                }
            }
            if ($all_req_present && $needs_one_other) {
                // need at least one more code beyond the required ones
                $other = array_diff($p['codes'], $req_concrete, [$med]);
                if (count($other) === 0) $all_req_present = false;
            }
            if ($all_req_present) {
                $anchored = true;
                $voltes_active[$spec['volta']] = true;
                break;
            }
        }
        if ($present) $mediator_status[$med] = $anchored;
    }

    if (empty($mediator_status)) {
        return [
            'synthesis_anchoring' => 0.0,
            'mediator_anchored' => [],
            'voltes_active' => [],
        ];
    }
    $anchored_count = count(array_filter($mediator_status, function($v) { return $v; }));
    return [
        'synthesis_anchoring' => $anchored_count / count($mediator_status),
        'mediator_anchored' => $mediator_status,
        'voltes_active' => array_keys($voltes_active),
    ];
}

/**
 * axis_explicit (v2): detects explicit dialectical-axis framing in the opening
 * (~600 chars) of the response. Binary [0,1]. A response that opens by naming
 * the axis it sits on shows the dialectical move at its very first sentence;
 * a response that opens by listing FEN data does not.
 */
function mg_wisdom_axis_explicit($text) {
    $opening = mb_substr($text, 0, 600);
    $cards = '(?:OBJ|SUB|TEO|PRA|FEN|NOU|PLA|MON)';
    $patterns = [
        '/\b' . $cards . '\s*(?:↔|--?|–|—|\/)\s*' . $cards . '\b/u',
        '/\b' . $cards . '[\s\-]+' . $cards . '\s+axis/u',
        '/\baxis\s*[:\-]?\s*(?:between|of|on|over|do)?[^\n]{0,140}\b' . $cards . '/u',
        '/\btension\s+(?:between|in|across)[^\n]{0,140}\b' . $cards . '/u',
        '/\bsits?\s+(?:on|at|in)\s+(?:an?|the)\s+[^\n]{0,80}\baxis\b/u',
        '/\bpresents?\s+(?:an?|the)\s+(?:axis|tension|polarity|dialectic)/u',
        '/\btwo\s+(?:readings|views|frames|ways|stances|paradigms|perspectives|horns)\b/u',
        '/\bcompeting\s+(?:readings|views|frames|paradigms|perspectives|positions|claims)\b/u',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $opening)) return 1.0;
    }
    return 0.0;
}

/**
 * subordinating_synthesis (v2): counts active mediation patterns. A subordinating
 * verb (subsumes, reframes, foregrounds, integrates, treats X as Y, etc.) signals
 * that one frame is doing something to another, not just being listed alongside it.
 * Plus chained-attribution patterns ("X is Y (frame1) Z (frame2) W (frame3)").
 * Saturates at 3 events.
 */
function mg_wisdom_subordinating_synthesis($text) {
    $strong_verbs = '(?:subsume[sd]?|reframe[sd]?|reframing|foreground[sd]?|dissolve[sd]?|integrat(?:e[sd]?|ing)|reconcil(?:e[sd]?|ing)|absorb[sd]?|encompass(?:e[sd]?)?|enable[sd]?|articulat(?:e[sd]?|ing)|press(?:es)?\s+harder|make[sd]?\s+possible|treats?\s+\w+\s+as|preserve[sd]?\s+\w+\s+(?:while|by|without)|answer[sd]?\s+the|address(?:e[sd]?)?\s+the|compose[sd]?\s+across|coexist\s+as)';
    $count = 0;
    if (preg_match_all('/' . $strong_verbs . '/iu', $text, $m)) {
        $count += count($m[0]);
    }
    // Chained attribution: e.g. "X (existentialist) Y (relational) Z (Stoic)"
    if (preg_match_all('/\([a-z]+(?:ist|ic|al|ian|ean)?\)[^()]{1,80}\([a-z]+(?:ist|ic|al|ian|ean)?\)[^()]{1,80}\([a-z]+(?:ist|ic|al|ian|ean)?\)/iu', $text, $cm)) {
        $count += count($cm[0]);
    }
    return min(1.0, $count / 3.0);
}

/**
 * Composite wisdom score (v2).
 *
 *   𝓦 = 0.05 · coverage
 *     + 0.05 · entropy_normalized
 *     + 0.20 · dialectical_pair_density
 *     + 0.20 · tension_density
 *     + 0.15 · synthesis_anchoring
 *     + 0.15 · axis_explicit
 *     + 0.20 · subordinating_synthesis
 */
function wisdomScore($text, $whitelist, $mereological_coverage = 0.0) {
    // 𝓦 v3 (deployed 2026-05-17, agenda item B.5): adds 𝓜 mereological_coverage
    // as 8th component with weight 0.15. Redistribution from v2:
    //   synthesis_anchoring 0.15 → 0.10  (−5pp)
    //   axis_explicit       0.15 → 0.10  (−5pp)
    //   subord_synthesis    0.20 → 0.15  (−5pp)
    //   mereological_coverage  0  → 0.15  (NEW)
    // Rationale: the 3 reduced components capture structural-dialectical
    // articulation; 𝓜 captures an orthogonal dimension (Part-Tot relations
    // canon coverage) that none of them measured.
    if (!is_string($text) || $text === '' || !is_array($whitelist)) {
        return [
            'coverage' => 0.0, 'entropy_normalized' => 0.0,
            'dialectical_pair_density' => 0.0, 'tension_density' => 0.0,
            'synthesis_anchoring' => 0.0,
            'axis_explicit' => 0.0, 'subordinating_synthesis' => 0.0,
            'mereological_coverage' => 0.0,
            'wisdom_score' => 0.0,
            'detail' => [
                'paired_axes' => [], 'tension_events' => [],
                'mediator_anchored' => [], 'cited_quadrants' => [],
                'voltes_active' => [],
            ],
        ];
    }
    $cited = mg_wisdom_extract_codes($text, $whitelist);
    $ce = mg_wisdom_coverage_entropy($cited, $whitelist);
    $dp = mg_wisdom_dialectical_pairs($text, $whitelist);
    $td = mg_wisdom_tension($text, $whitelist);
    $sa = mg_wisdom_synthesis($text, $whitelist);
    $ax = mg_wisdom_axis_explicit($text);
    $ss = mg_wisdom_subordinating_synthesis($text);
    $mc = max(0.0, min(1.0, (float)$mereological_coverage));

    $wisdom =
        0.05 * $ce['coverage']
      + 0.05 * $ce['entropy_normalized']
      + 0.20 * $dp['dialectical_pair_density']
      + 0.20 * $td['tension_density']
      + 0.10 * $sa['synthesis_anchoring']
      + 0.10 * $ax
      + 0.15 * $ss
      + 0.15 * $mc;

    return [
        'coverage'                 => round($ce['coverage'], 6),
        'entropy_normalized'       => round($ce['entropy_normalized'], 6),
        'dialectical_pair_density' => round($dp['dialectical_pair_density'], 6),
        'tension_density'          => round($td['tension_density'], 6),
        'synthesis_anchoring'      => round($sa['synthesis_anchoring'], 6),
        'axis_explicit'            => round($ax, 6),
        'subordinating_synthesis'  => round($ss, 6),
        'mereological_coverage'    => round($mc, 6),
        'wisdom_score'             => round($wisdom, 6),
        'detail' => [
            'paired_axes'       => $dp['paired_axes'],
            'tension_events'    => $td['tension_events'],
            'mediator_anchored' => $sa['mediator_anchored'],
            'cited_quadrants'   => $ce['cited_quadrants'],
            'voltes_active'     => $sa['voltes_active'],
        ],
    ];
}

} // end function_exists guard
