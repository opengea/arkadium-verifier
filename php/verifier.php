<?php
/**
 * verifier.php
 *
 * Structural verifier for Meta-Globàlium-anchored agent responses.
 *
 * Public function:
 *   verifyResponse(string $response_text, ?array $cited_categories = null): array
 *
 * If $cited_categories is null, codes are extracted from $response_text via the
 * regex /\b[A-Z]{2,4}[0-9]?\b/u and filtered against the whitelist of 90 codes
 * loaded from data/categories_codes.json. This automatically discards English
 * words like "USA" or "CEO" because they are not in the whitelist.
 *
 * Each code is mapped to its primary quadrant (PLA/MON/SUB/OBJ/TEO/PRA/FEN/NOU)
 * via the pre-computed map in categories_codes.json. We compute:
 *   - quadrants_touched (ordered, distinct list)
 *   - n_quadrants
 *   - Shannon entropy of the citation distribution per quadrant
 *   - normalized_entropy = entropy / log(8)  (0..1)
 *   - harmonic_score = (n_quadrants / 8) * 0.5 + normalized_entropy * 0.5
 *   - needs_reprompt = (n_quadrants < 3)
 *   - missing_quadrants = list of quadrants with 0 citations
 *   - voltes_suggested = list of voltes mentioned in the text (regex)
 *
 * Return shape (associative array):
 * [
 *   'cited_categories'   => [...],
 *   'quadrants_touched'  => [...],
 *   'quadrant_counts'    => ['PLA'=>2, 'TEO'=>1, ...],
 *   'n_quadrants'        => int,
 *   'entropy'            => float,
 *   'normalized_entropy' => float,
 *   'harmonic_score'     => float,
 *   'needs_reprompt'     => bool,
 *   'missing_quadrants'  => [...],
 *   'voltes_suggested'   => [...]
 * ]
 *
 * @see plan.md T4
 */

if (!function_exists('mg_load_codes_map')) {

/**
 * Load (and cache) the categories_codes.json whitelist + quadrant map.
 *
 * @return array ['whitelist' => label=>quadrant, 'quadrants' => [PLA,...,NOU]]
 */
function mg_load_codes_map() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $fallback = [
        'whitelist' => [
            'PLA' => 'PLA', 'MON' => 'MON',
            'SUB' => 'SUB', 'OBJ' => 'OBJ',
            'TEO' => 'TEO', 'PRA' => 'PRA',
            'FEN' => 'FEN', 'NOU' => 'NOU',
        ],
        'whitelist_full' => [
            'PLA' => ['primary' => 'PLA', 'secondary' => null],
            'MON' => ['primary' => 'MON', 'secondary' => null],
            'SUB' => ['primary' => 'SUB', 'secondary' => null],
            'OBJ' => ['primary' => 'OBJ', 'secondary' => null],
            'TEO' => ['primary' => 'TEO', 'secondary' => null],
            'PRA' => ['primary' => 'PRA', 'secondary' => null],
            'FEN' => ['primary' => 'FEN', 'secondary' => null],
            'NOU' => ['primary' => 'NOU', 'secondary' => null],
        ],
        'quadrants' => ['PLA','MON','SUB','OBJ','TEO','PRA','FEN','NOU'],
    ];

    $path = __DIR__ . '/data/categories_codes.json';
    if (!is_readable($path)) {
        $cached = $fallback;
        return $cached;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['whitelist'])) {
        $cached = $fallback;
        return $cached;
    }
    // Build whitelist_full from the JSON's whitelist_full if present, else
    // synthesize from the simple whitelist (no secondary quadrants).
    $wf = isset($data['whitelist_full']) && is_array($data['whitelist_full'])
        ? $data['whitelist_full']
        : null;
    if ($wf === null) {
        $wf = [];
        foreach ($data['whitelist'] as $label => $q) {
            $wf[$label] = ['primary' => $q, 'secondary' => null];
        }
    }
    // Build label→tipus mapping (Plasmàtica/Neutral/Mundana) for tempeternal_coverage (added 2026-05-02)
    $tipus_map = [];
    if (isset($data['entries']) && is_array($data['entries'])) {
        foreach ($data['entries'] as $entry) {
            if (isset($entry['label']) && isset($entry['tipus'])) {
                $tipus_map[$entry['label']] = $entry['tipus'];
            }
        }
    }

    $cached = [
        'whitelist'      => $data['whitelist'],
        'whitelist_full' => $wf,
        'tipus_map'      => $tipus_map,
        'quadrants'      => isset($data['quadrants']) ? $data['quadrants']
                            : ['PLA','MON','SUB','OBJ','TEO','PRA','FEN','NOU'],
    ];
    return $cached;
}

/**
 * Detect Cicle references in free text.
 *
 * @param string $text
 * @return array unique list of Cicle names mentioned
 */
/**
 * Mapping canònic d'estacions per cicle (2026-05-02)
 * Cada cicle té 8 estacions canòniques. Reutilitzat per detection i coverage.
 */
function mg_volta_stations() {
    return [
        'Mètode'        => ['FEN','ANA','TEO','SIN','NOU','AMO','PRA','EXP'],
        'Coneixement'   => ['FEN','ART','SUB','MTP','NOU','MTF','OBJ','CIE'],
        'Revelació'     => ['PRA','STM','SUB','STT','TEO','SGT','OBJ','SGE'],
        'Universal'     => ['TEO','CAS','PRA','COS','MON','COV','PLA','CAV'],
        'Relació'       => ['NOU','CFN','FEN','CMN','MON','EXC','PLA','ATZ'],
        'Consistència'  => ['SUB','FEL','OBJ','INT','MON','AFI','PLA','BOS'],
    ];
}

function mg_detect_voltes($text, $cited_codes = null) {
    // Dual-match: prompts nous diuen "Cicle del/de la X", però mantenim
    // detecció de "Volta de X" durant transició per si l'LLM encara emet
    // el terme antic (instruccions de prompt no sempre són perfectament
    // seguides). Treure'l després d'observar logs en estable.
    $patterns = [
        'Coneixement'   => '/\b(?:Cicle\s+del|Volta\s+de)\s+Coneixement\b/iu',
        'Mètode'        => '/\b(?:Cicle\s+del|Volta\s+de)\s+M[èe]tode\b/iu',
        'Revelació'     => '/\b(?:Cicle\s+de\s+la|Volta\s+de)\s+Revelaci[óo]\b/iu',
        'Universal'     => '/\b(?:Cicle|Volta)\s+Universal\b/iu',
        'Relació'       => '/\b(?:Cicle\s+de\s+la|Volta\s+de)\s+Relaci[óo]\b/iu',
        'Consistència'  => '/\b(?:Cicle\s+de\s+la|Volta\s+de)\s+Consist[èe]ncia\b/iu',
    ];
    $found = [];
    foreach ($patterns as $name => $regex) {
        if (preg_match($regex, $text)) {
            $found[] = $name;
        }
    }

    // Inferència per categories citades (2026-05-02)
    // Si 3+ estacions d'un cicle apareixen, suggereix el cicle encara que no sigui mencionat literalment
    if (is_array($cited_codes) && !empty($cited_codes)) {
        $cicle_stations = mg_volta_stations();
        $cited_set = array_flip($cited_codes);
        foreach ($cicle_stations as $cicle_name => $stations) {
            $hits = 0;
            foreach ($stations as $st) {
                if (isset($cited_set[$st])) $hits++;
            }
            // 3+ estacions = cicle inferit (excepte si ja estava per mention literal)
            if ($hits >= 3 && !in_array($cicle_name, $found, true)) {
                $found[] = $cicle_name;
            }
        }
    }

    return $found;
}

/**
 * Calcula cobertura per a cada cicle detectat (2026-05-02).
 * Retorna [cicle_name => ['coverage'=>0..1, 'hits'=>int, 'total'=>8, 'stations_touched'=>[...]]]
 */
function mg_voltes_coverage($cited_codes, $voltes_suggested) {
    $result = [];
    if (!is_array($cited_codes) || !is_array($voltes_suggested) || empty($voltes_suggested)) {
        return $result;
    }
    $stations_map = mg_volta_stations();
    $cited_set = array_flip($cited_codes);
    foreach ($voltes_suggested as $cicle_name) {
        if (!isset($stations_map[$cicle_name])) continue;
        $stations = $stations_map[$cicle_name];
        $touched = [];
        foreach ($stations as $st) {
            if (isset($cited_set[$st])) $touched[] = $st;
        }
        $hits = count($touched);
        $total = count($stations);
        $result[$cicle_name] = [
            'coverage'         => round($hits / $total, 4),
            'hits'             => $hits,
            'total'            => $total,
            'stations_touched' => $touched,
        ];
    }
    return $result;
}

/**
 * Extract plausible category codes from text (2-4 uppercase letters, optional digit).
 * Filters against the whitelist. Returns ordered list with first-occurrence preserved
 * but only unique codes.
 *
 * @param string $text
 * @param array $whitelist label => quadrant
 * @return array list of codes
 */
function mg_extract_codes($text, $whitelist) {
    if (!preg_match_all('/\b[A-Z]{2,4}[0-9]?\b/u', $text, $matches)) {
        return [];
    }
    $seen = [];
    $out  = [];
    foreach ($matches[0] as $tok) {
        if (isset($whitelist[$tok]) && !isset($seen[$tok])) {
            $seen[$tok] = true;
            $out[] = $tok;
        }
    }
    return $out;
}

/**
 * Extract an explicit ordered path of category codes from the response.
 * The agent emits "[PATH: ANA, SIN, AMO, EXP]" (or with arrows) on a single
 * line for procedural / how-to questions. The codes are validated against
 * the whitelist; duplicates are removed (first-occurrence kept).
 *
 * @param string $text
 * @param array  $whitelist  label => quadrant
 * @return array ['path' => [codes...], 'cleaned' => text without the marker]
 */
function mg_extract_path($text, $whitelist) {
    // Match the [PATH: ...] marker even when the inner content is empty,
    // whitespace-only, or just commas — we still want to strip it from the
    // displayed answer rather than leaking "[PATH: , , , ]" to the user.
    if (!preg_match('/\[PATH:\s*([^\]\n\r]*)\]/iu', $text, $m)) {
        return ['path' => [], 'cleaned' => $text];
    }
    $tokens = preg_split('/[,\s\x{2192}\->]+/u', trim($m[1]));
    $path = [];
    $seen = [];
    foreach ($tokens as $tok) {
        $tok = strtoupper(trim($tok));
        if ($tok === '') continue;
        if (preg_match('/^[A-Z]{2,4}[0-9]?$/', $tok) && isset($whitelist[$tok]) && !isset($seen[$tok])) {
            $seen[$tok] = true;
            $path[] = $tok;
        }
    }
    $cleaned = preg_replace('/\[PATH:\s*[^\]\n\r]*\]\s*\n?/iu', '', $text);
    return ['path' => $path, 'cleaned' => trim($cleaned)];
}

/**
 * Main public verifier function.
 *
 * @param string $response_text     Free-text response from the LLM
 * @param array|null $cited_categories optional pre-extracted list of codes; if null, extracted from text
 * @return array shape described in file header
 */
function verifyResponse($response_text, $cited_categories = null) {
    $map = mg_load_codes_map();
    $whitelist = $map['whitelist'];
    $whitelist_full = $map['whitelist_full'];
    $quadrants = $map['quadrants']; // canonical 8

    // 1. Determine cited codes
    if (is_array($cited_categories)) {
        $codes = [];
        $seen = [];
        foreach ($cited_categories as $c) {
            $c = is_string($c) ? trim($c) : '';
            if ($c === '') continue;
            $cu = $c;
            if (isset($whitelist[$cu]) && !isset($seen[$cu])) {
                $seen[$cu] = true;
                $codes[] = $cu;
            }
        }
    } else {
        $codes = mg_extract_codes((string)$response_text, $whitelist);
    }

    // 2. Aggregate per quadrant. Each cited code contributes 1.0 to its primary
    //    quadrant. If it has a secondary quadrant (near-tie axis), it contributes
    //    0.5 to that secondary too. This reflects the holistic property of
    //    Meta-Globàlium: a category at the surface with three near-equal axes
    //    legitimately touches more than one quadrant.
    $counts = array_fill_keys($quadrants, 0.0);
    foreach ($codes as $c) {
        $entry = isset($whitelist_full[$c])
            ? $whitelist_full[$c]
            : ['primary' => $whitelist[$c], 'secondary' => null];
        $p = $entry['primary'];
        $s = $entry['secondary'];
        if (isset($counts[$p])) {
            $counts[$p] += 1.0;
        }
        if ($s !== null && $s !== $p && isset($counts[$s])) {
            $counts[$s] += 0.5;
        }
    }
    $quadrants_touched = [];
    foreach ($counts as $q => $n) {
        if ($n > 0) $quadrants_touched[] = $q;
    }
    $n_quadrants = count($quadrants_touched);
    $missing_quadrants = array_values(array_diff($quadrants, $quadrants_touched));

    // 3. Shannon entropy of distribution over quadrants (using fractional counts)
    $total = array_sum($counts);
    $entropy = 0.0;
    if ($total > 0) {
        foreach ($counts as $n) {
            if ($n > 0) {
                $pp = $n / $total;
                $entropy -= $pp * log($pp);
            }
        }
    }
    $log8 = log(8);
    $normalized_entropy = ($log8 > 0) ? ($entropy / $log8) : 0.0;
    if ($normalized_entropy < 0) $normalized_entropy = 0.0;
    if ($normalized_entropy > 1) $normalized_entropy = 1.0;

    // 4. Harmonic score
    $coverage = $n_quadrants / 8.0;
    $harmonic_score = $coverage * 0.5 + $normalized_entropy * 0.5;

    // 5. Needs reprompt
    $needs_reprompt = ($n_quadrants < 3);

    // 6. Voltes
    $voltes_suggested = mg_detect_voltes((string)$response_text, $codes);
    $voltes_coverage  = mg_voltes_coverage($codes, $voltes_suggested);

    // 7. Explicit path extraction (procedural / "how-to" responses)
    $pathInfo = mg_extract_path((string)$response_text, $whitelist);

    // Cast counts to a cleaner numeric form for JSON serialization:
    $counts_out = [];
    foreach ($counts as $q => $n) {
        // Keep as float when fractional, otherwise int.
        $counts_out[$q] = (floor($n) == $n) ? (int)$n : (float)$n;
    }

    // 8. Canonical correspondences (added 2026-05-02 — see docs/canonical-mappings.md)
    //    Three additional dimensions of coverage:
    //    - Aristotelian causal coverage
    //    - Epistemological coverage (3 inference types)
    //    - Solve-Coagula balance (alchemical macro-dialectic)

    // 8a. Aristotelian causes ↔ NEUs (redistribuit 2026-05-15)
    // El Meta-Globàlium redistribueix les causes aristotèliques al seu lloc natural:
    //   - material → OBJ (substrat extens)
    //   - formal essencial → NOU (eidos atemporal)
    //   - formal genètica → PLA (patró replegat, llavor codificada)
    //   - eficient → SUB (l'agent)
    //   - final → MON (entelécheia, desplegament madur)
    //   - exemplar → DIV
    //   - contextual → FEN
    //   - essencial → ORG
    // Nova: la causa formal de Aristòtil es desdobla en 2 lectures (essencial/genètica)
    // que viuen a llocs distints, aportació original del Meta-Globàlium.
    $cause_map = [
        'OBJ' => ['material'],
        'NOU' => ['formal_essential'],
        'PLA' => ['formal_genetic'],
        'SUB' => ['efficient'],
        'MON' => ['final'],
        'DIV' => ['exemplary'],
        'FEN' => ['contextual'],
        'ORG' => ['essential'],
    ];
    $causes_touched = [];
    foreach ($codes as $c) {
        if (isset($cause_map[$c])) {
            foreach ($cause_map[$c] as $cause) {
                if (!in_array($cause, $causes_touched, true)) $causes_touched[] = $cause;
            }
        }
    }
    $all_causes = ['material','formal_essential','formal_genetic','efficient','final','exemplary','contextual','essential'];
    $causal_coverage = count($causes_touched) / count($all_causes);

    // 8b. Inference types ↔ NEUs
    $inference_map = [
        'LOG' => 'deduction', 'ANA' => 'deduction',
        'IDE' => 'induction', 'SIN' => 'induction',
        'EST' => 'abduction_selective',
        'MIT' => 'abduction_creative',
    ];
    $inferences_touched = [];
    foreach ($codes as $c) {
        if (isset($inference_map[$c])) {
            $inf = $inference_map[$c];
            if (!in_array($inf, $inferences_touched, true)) $inferences_touched[] = $inf;
        }
    }
    $all_inferences = ['deduction','induction','abduction_selective','abduction_creative'];
    $epistemic_coverage = count($inferences_touched) / count($all_inferences);

    // 8c. Solve-Coagula balance (alchemical macro-dialectic)
    $solve_codes = ['ANA','CIE'];
    $coagula_codes = ['SIN','MTP'];
    $solve_count = 0; $coagula_count = 0;
    foreach ($codes as $c) {
        if (in_array($c, $solve_codes, true)) $solve_count++;
        if (in_array($c, $coagula_codes, true)) $coagula_count++;
    }
    // Balance metric: 1.0 if both sides equally represented; 0.0 if all on one side or none cited
    $sc_total = $solve_count + $coagula_count;
    if ($sc_total === 0) {
        $solve_coagula_balance = 0.0;
    } else {
        $solve_ratio = $solve_count / $sc_total;
        // Distance from 0.5 — closer to 0.5 means more balanced
        $solve_coagula_balance = 1.0 - 2.0 * abs($solve_ratio - 0.5);
    }

    // 8d. Tempeternal coverage (D4 radial, ADOPTED 2026-05-02, RECTIFIED 2026-05-09)
    //     Mesura profunditat de batec radial al llarg del repleg-desplegament:
    //     PLA-side (Plasmàtica) = llavors radicals / potencialitats replegades
    //                              (NO atemporalitat — això viu a NOU dins D3)
    //     NEU (Neutral) = balanç dialèctic operatiu / equilibri present
    //     MON-side (Mundana) = desplegament madur / manifestació integrada
    //                          (NO temporalitat — això viu a FEN dins D3)
    //     Una resposta integrada toca les 3 capes; saviesa = integració del batec.
    $tipus_map = isset($map['tipus_map']) ? $map['tipus_map'] : [];
    $pla_side_count = 0; $neu_side_count = 0; $mon_side_count = 0;
    foreach ($codes as $c) {
        if (isset($tipus_map[$c])) {
            $t = $tipus_map[$c];
            if ($t === 'Plasmàtica') $pla_side_count++;
            elseif ($t === 'Neutral') $neu_side_count++;
            elseif ($t === 'Mundana') $mon_side_count++;
        }
    }
    // Tempeternal balance: 1.0 if all 3 layers represented; 0.0 if only 1 or none
    $layers_touched = 0;
    if ($pla_side_count > 0) $layers_touched++;
    if ($neu_side_count > 0) $layers_touched++;
    if ($mon_side_count > 0) $layers_touched++;
    $tempeternal_coverage = $layers_touched / 3.0;

    // Tempeternal balance via entropy (more nuanced than just count of layers touched)
    $tte_total = $pla_side_count + $neu_side_count + $mon_side_count;
    $tempeternal_balance = 0.0;
    if ($tte_total > 0) {
        $entropy_tte = 0.0;
        foreach ([$pla_side_count, $neu_side_count, $mon_side_count] as $n) {
            if ($n > 0) {
                $pp = $n / $tte_total;
                $entropy_tte -= $pp * log($pp);
            }
        }
        $log3 = log(3);
        $tempeternal_balance = ($log3 > 0) ? ($entropy_tte / $log3) : 0.0;
    }

    // Per-cited-code tipus mapping (short form: 'pla'/'neu'/'mun') for the
    // frontend to render chips with type-aware borders / colors.
    $cited_tipus_map = [];
    foreach ($codes as $c) {
        if (isset($tipus_map[$c])) {
            $t = $tipus_map[$c];
            if ($t === 'Plasmàtica')      $cited_tipus_map[$c] = 'pla';
            elseif ($t === 'Neutral')     $cited_tipus_map[$c] = 'neu';
            elseif ($t === 'Mundana')     $cited_tipus_map[$c] = 'mun';
        }
    }

    // Wisdom Score 𝓦 v2 — relational depth (added 2026-05-07).
    // Computed alongside 𝓗 so the structural re-prompt loop can target individual
    // Mereological coverage 𝓜 — Xirinacs § 422 (added 2026-05-15, item B.5).
    // Comprova que la resposta toca les 4 relacions Part-Tot canòniques:
    //   - A=A (autoidentitat) — Vigília — meridià LOG-CIE-TEC
    //   - A⊂T (inclusió al tot) — Creença — meridià IDE-MTF-ETI
    //   - T⊂A (contenció del tot) — Deliri — meridià MIT-MTP-MIS
    //   - A⊂B (correlació amb altres) — Somni — meridià EST-ART-PSI
    // Una sortida que toca 3 quadrants pot ser estructuralment àmplia però
    // mereològicament pobra si només treballa A=A. Aquesta mètrica detecta
    // aquell biaix. Combina senyal categòric (categories diagonals citades)
    // amb senyal lèxic (patrons multilingues).
    // NOTA: calculat aquí (abans del wisdom) perquè 𝓦 v3 (2026-05-17, B.5)
    // integra mereological_coverage com a 8è component amb pes 0.15.
    $mereological = mg_mereological_coverage((string)$response_text, $codes);

    // wisdom components (axis, subord, tens, syn, pair, mereological) with specific feedback.
    $wisdom = ['wisdom_score' => 0.0,
               'coverage_w' => 0.0, 'entropy_w' => 0.0,
               'dialectical_pair_density' => 0.0, 'tension_density' => 0.0,
               'synthesis_anchoring' => 0.0,
               'axis_explicit' => 0.0, 'subordinating_synthesis' => 0.0,
               'mereological_coverage_w' => 0.0];
    if (is_string($response_text) && $response_text !== '') {
        $wisdom_path = __DIR__ . '/wisdom_score.php';
        if (file_exists($wisdom_path)) {
            require_once $wisdom_path;
        }
        if (function_exists('wisdomScore')) {
            // Pass mereological_coverage to wisdomScore for 𝓦 v3 composite.
            $wraw = wisdomScore($response_text, $whitelist, (float)($mereological['coverage'] ?? 0.0));
            $wisdom['wisdom_score']            = $wraw['wisdom_score'] ?? 0.0;
            $wisdom['coverage_w']              = $wraw['coverage'] ?? 0.0;
            $wisdom['entropy_w']               = $wraw['entropy_normalized'] ?? 0.0;
            $wisdom['dialectical_pair_density']= $wraw['dialectical_pair_density'] ?? 0.0;
            $wisdom['tension_density']         = $wraw['tension_density'] ?? 0.0;
            $wisdom['synthesis_anchoring']     = $wraw['synthesis_anchoring'] ?? 0.0;
            $wisdom['axis_explicit']           = $wraw['axis_explicit'] ?? 0.0;
            $wisdom['subordinating_synthesis'] = $wraw['subordinating_synthesis'] ?? 0.0;
            $wisdom['mereological_coverage_w'] = $wraw['mereological_coverage'] ?? 0.0;
        }
    }

    // Implicit coverage ℑ — semantic touch detection (added 2026-05-07).
    // Detects conceptual presence of each cardinal via natural-language terms,
    // independent of whether the response uses canonical codes (OBJ, SUB, ...).
    // Diagnostic complement to 𝓗 (which requires explicit anchoring).
    // Used to render a "ghost compass" for bare LLM responses showing what they
    // touch conceptually even without operating in the Meta-Globàlium vocabulary.
    $implicit = mg_implicit_coverage((string)$response_text);

    return [
        'cited_categories'   => $codes,
        'cited_tipus_map'    => $cited_tipus_map,
        'quadrants_touched'  => $quadrants_touched,
        'quadrant_counts'    => $counts_out,
        'n_quadrants'        => $n_quadrants,
        'entropy'            => round($entropy, 6),
        'normalized_entropy' => round($normalized_entropy, 6),
        'harmonic_score'     => round($harmonic_score, 6),
        'needs_reprompt'     => $needs_reprompt,
        'missing_quadrants'  => $missing_quadrants,
        'voltes_suggested'   => $voltes_suggested,
        'voltes_coverage'    => $voltes_coverage,
        'path'               => $pathInfo['path'],
        'cleaned_text'       => $pathInfo['cleaned'],
        // Canonical correspondences (2026-05-02)
        'causal_coverage'    => round($causal_coverage, 4),
        'causes_touched'     => $causes_touched,
        'epistemic_coverage' => round($epistemic_coverage, 4),
        'inferences_touched' => $inferences_touched,
        'solve_coagula_balance' => round($solve_coagula_balance, 4),
        'solve_count'        => $solve_count,
        'coagula_count'      => $coagula_count,
        // Tempeternitat — D4 transversal (ADOPTED 2026-05-02)
        'tempeternal_coverage' => round($tempeternal_coverage, 4),
        'tempeternal_balance'  => round($tempeternal_balance, 4),
        'pla_side_count'       => $pla_side_count,
        'neu_side_count'       => $neu_side_count,
        'mon_side_count'       => $mon_side_count,
        // Wisdom Score 𝓦 v3 (updated 2026-05-17 with mereological component, agenda B.5)
        'wisdom_score'             => round($wisdom['wisdom_score'], 4),
        'wisdom_axis_explicit'     => round($wisdom['axis_explicit'], 4),
        'wisdom_subord_synthesis'  => round($wisdom['subordinating_synthesis'], 4),
        'wisdom_dialectical_pair'  => round($wisdom['dialectical_pair_density'], 4),
        'wisdom_tension_density'   => round($wisdom['tension_density'], 4),
        'wisdom_synthesis_anchor'  => round($wisdom['synthesis_anchoring'], 4),
        'wisdom_mereological'      => round($wisdom['mereological_coverage_w'], 4),
        // Implicit coverage ℑ — semantic touch (added 2026-05-07)
        'implicit_coverage'         => round($implicit['coverage'], 4),
        'implicit_quadrants_touched'=> $implicit['quadrants_touched'],
        'implicit_distribution'     => $implicit['distribution'],
        // Factual density 𝓕 — concreteness counter (added 2026-05-07).
        // Penalises responses that score high on dialectical structure (𝓦) but
        // evacuate authors, data, and specific cases ("abstract dialectical prose").
        'factual_density'           => round(mg_factual_density((string)$response_text), 4),
        // Mereological coverage 𝓜 — Xirinacs § 422 Part-Tot (added 2026-05-15, B.5)
        'mereological_coverage'     => round($mereological['coverage'], 4),
        'mereological_count'        => $mereological['count'],
        'mereological_relations'    => $mereological['relations_touched'],
        'mereological_signals'      => $mereological['signals'],
        'consciousness_states'      => $mereological['consciousness_states'],
    ];
}

/**
 * Factual density 𝓕 — counts concrete-content markers per response.
 * Returns a normalised value in [0, 1] where 1 ≈ 8 facts per 100 words
 * (saturation point above which more density doesn't add value).
 *
 * Counts (with weights):
 *   - Multi-word proper-noun phrases (excluding -ing/-ed/-ly/-al verbal forms)
 *   - Non-canonical acronyms (filtered against Meta-Globàlium code list)
 *   - Author cite patterns "Lastname (Year)" or "Lastname et al."
 *   - Numerals with units (%, km/h, years, etc.)
 *   - Standalone four-digit years
 *
 * NOT a substitute for human judgement — diagnostic complement that flags
 * the failure mode where Arkadium loop optimises 𝓦 at the cost of concreteness.
 */
function mg_factual_density($text) {
    if (!is_string($text) || $text === '') return 0.0;
    $count = 0;

    // 1. Multi-word proper-noun phrases. First word must NOT be a verbal/adjectival form.
    if (preg_match_all('/\b(?!(?:[A-Z][a-z]*(?:ing|ed|ly|al|ical|ous|tive|able|istic|ish))\b)[A-Z][a-z]+(?:\s+(?:of|the|de|für|et|al|i|and|for)\s+)?(?:[A-Z][a-z]+)+\b/', $text, $m)) {
        $valid = array_filter($m[0], function($s) {
            $words = preg_split('/\s+/', $s);
            $verbal = 0;
            foreach ($words as $w) {
                if (preg_match('/^[A-Z][a-z]*(ing|ed|ly|al|ical|ous|tive|able|istic|ish)$/', $w)) $verbal++;
            }
            return $verbal <= count($words) / 2;
        });
        $count += count($valid) * 1.5;
    }

    // 2. Non-canonical acronyms (filter Meta-Globàlium codes).
    static $cardinals = ['PLA','MON','SUB','OBJ','TEO','PRA','FEN','NOU',
        'ANA','SIN','AMO','EXP','STM','STT','SGT','SGE',
        'ART','MTP','MTF','CIE','LOG','EST','IDE','MIT','TEC','PSI','MIS','ETI',
        'HAR','DET','COM','ECN','ECL','EXC','POL','PCS','AGU','FUN','BEL','COS',
        'COV','INT','AFI','DSG','AST','OBL','CNV','CMN','RGN','GEN','OGN','ECU',
        'ARQ','DIV','FEL','BOS','EBR','FOL','PRD','RAR','CFN','MGM','SLM','LET',
        'ORG','APE','AKA','ARK','TIA','ATZ','TRB','PRB','TRS','ONA','ACC','PAS',
        'IDT','GLO','CAS','CAV','RGE'];
    if (preg_match_all('/\b[A-Z]{2,5}\b/', $text, $m)) {
        $non_canonical = array_filter($m[0], function($a) use ($cardinals) {
            return !in_array($a, $cardinals, true);
        });
        $count += count($non_canonical) * 0.8;
    }

    // 3. Author cite patterns (Lastname (YYYY) or Lastname et al.)
    if (preg_match_all('/[A-Z][a-z]+(?:\s+et\s+al\.)?\s*\(\d{4}\)/', $text, $m)) {
        $count += count($m[0]) * 2.5;
    }

    // 4. Numerals with units
    if (preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:%|km\/h|km|kg|years?|months?|hours?|million|billion|euros?|dollars?|x|\$)\b/iu', $text, $m)) {
        $count += count($m[0]) * 1.5;
    }

    // 5. Years
    if (preg_match_all('/\b(?:19|20)\d{2}\b/', $text, $m)) {
        $count += count($m[0]);
    }

    $words = max(1, str_word_count($text));
    $density = $count / ($words / 100.0);
    return round(min(1.0, $density / 8.0), 4);
}

/**
 * Term lexicon for implicit coverage detection. Each cardinal points to a list
 * of natural-language terms (lowercase, word-boundary matched). Multilingual
 * (EN/CA/ES). Curated to minimise overlaps — terms ambiguous between cardinals
 * are assigned to a single "primary" mapping or omitted.
 *
 * IMPORTANT: This is a DIAGNOSTIC complement to 𝓗, not a replacement. ℑ
 * detects conceptual touch; 𝓗 measures explicit Meta-Globàlium anchoring.
 */
function mg_implicit_terms() {
    return [
        'SUB' => [
            // EN
            'subjective','subjectivity','self','oneself','inner','inward',
            'feeling','felt','emotion','intuition','personal','individual',
            'agency','autonomy','consciousness','identity','perspective',
            'first-person','viewpoint','introspection',
            // CA
            'subjectiu','subjectivitat','jo','intern','sentit','sentiment',
            'emoció','intuïció','personal','individual','agència','autonomia',
            'consciència','identitat','perspectiva','introspecció',
            // ES
            'subjetivo','subjetividad','interno','sentimiento','emoción',
            'intuición','agencia','autonomía','conciencia','identidad',
        ],
        'OBJ' => [
            // EN
            'objective','objectivity','external','outer','structure','structural',
            'material','physical','observable','measurable','quantifiable',
            'third-person','data','statistic','statistical','factual','empirical',
            'institution','institutional','infrastructure','system',
            // CA
            'objectiu','objectivitat','extern','estructura','estructural',
            'material','físic','mesurable','quantificable','dada','dades',
            'estadística','factual','empíric','institució','institucional',
            'infraestructura','sistema',
            // ES
            'objetivo','objetividad','externo','estructura','estructural',
            'material','físico','medible','cuantificable','dato','datos',
            'estadística','empírico','institución','infraestructura','sistema',
        ],
        'TEO' => [
            // EN
            'theory','theoretical','theoretically','principle','idea','concept',
            'abstract','abstraction','framework','paradigm','reasoning',
            'philosophical','philosophy','intellectual','rationality',
            // CA
            'teoria','teòric','teòrica','principi','idea','concepte','abstracte',
            'abstracció','marc','paradigma','raonament','filosòfic','filosofia',
            'intel·lectual','racionalitat',
            // ES
            'teoría','teórico','teórica','principio','idea','concepto','abstracto',
            'abstracción','marco','paradigma','razonamiento','filosófico',
            'filosofía','intelectual','racionalidad',
        ],
        'PRA' => [
            // EN
            'practice','practical','practically','action','acting','application',
            'apply','implement','implementation','doing','behavior','behaviour',
            'behavioral','tangible','concrete','operational','enact','enactment',
            // CA
            'pràctica','pràctic','acció','actuar','aplicar','aplicació',
            'implementar','implementació','comportament','tangible','concret',
            'operatiu','realitzar',
            // ES
            'práctica','práctico','acción','actuar','aplicar','aplicación',
            'implementar','implementación','comportamiento','tangible','concreto',
            'operativo','realizar',
        ],
        'FEN' => [
            // D3+ pole — phenomenon, time, here-and-now (rectified 2026-05-09:
            // temporality belongs HERE, not in MON).
            // EN
            'phenomenon','phenomena','phenomenal','observed','immediate',
            'manifest','appearance','appearances','event','occurrence','situation',
            'visible','surface','observable','time','temporal','temporality',
            'chronological','here-and-now','present moment','ongoing','current',
            'history of','succession','sequential','timely',
            // CA
            'fenomen','fenòmens','fenomenal','observat','immediat','manifestar',
            'aparició','esdeveniment','situació','superfície','visible',
            'temps','temporal','temporalitat','cronològic','present','presents',
            'aquí i ara','en curs','actualitat','història de','successió','seqüencial',
            // ES
            'fenómeno','fenómenos','fenomenal','observado','inmediato',
            'manifestación','apariencia','evento','suceso','situación',
            'superficie','visible',
            'tiempo','temporal','temporalidad','cronológico','presente',
            'aquí y ahora','en curso','actualidad','historia de','sucesión','secuencial',
        ],
        'NOU' => [
            // D3- pole — noumenon, eternity, atemporality (rectified 2026-05-09:
            // atemporality belongs HERE, not in PLA).
            // EN
            'noumenon','noumenal','essence','essential','meaning','depth',
            'underlying','beneath','transcendent','transcendental','metaphysical',
            'beyond','intrinsic',
            'eternal','eternity','atemporal','atemporality','timeless',
            'sub specie aeternitatis','eternal present','sub-aeternal',
            // CA
            'noümen','noümenal','essència','essencial','sentit','profund',
            'subjacent','transcendent','transcendental','metafísic','intrínsec',
            'etern','eternitat','atemporal','atemporalitat','sense temps',
            // ES
            'noúmeno','noumenal','esencia','esencial','sentido','profundidad',
            'subyacente','transcendente','transcendental','metafísico','intrínseco',
            'eterno','eternidad','atemporal','atemporalidad','sin tiempo',
        ],
        'PLA' => [
            // D4 inner pole — radical seed, folded potentiality (rectified
            // 2026-05-09: NOT atemporality — that lives at NOU on D3).
            // EN
            'potential','potentiality','seed','origin','source','foundational',
            'primordial','plasma','archetype','archetypal','latent','virtual','womb',
            'folded','radical seed','implicate','pre-articulated','germinal',
            'enfolded','regenerative','indeterminate','primal',
            // CA
            'llavor','origen','font','fonamental','primordial',
            'plasma','arquetip','arquetípic','latent','virtual','ventre',
            'replegat','llavor radical','implicat','pre-articulat','germinal',
            'regeneratiu','indeterminat','primigeni',
            // ES
            'semilla','origen','fuente','fundamental',
            'primordial','arquetipo','arquetípico','latente','virtual',
            'replegado','semilla radical','implicado','pre-articulado','germinal',
            'regenerativo','indeterminado','primigenio',
        ],
        'MON' => [
            // D4 outer pole — mature deployment, integrated manifestation
            // (rectified 2026-05-09: NOT temporality — that lives at FEN on D3).
            // EN
            'world','worldly','manifested','unfolded','deployed','mature',
            'integrated','complex','complexity','realised','realized','enacted',
            'actualised','full deployment','explicate','mundane','consummated',
            // CA
            'món','mundà','manifestat','desplegat','desplegament','madur',
            'integrat','complex','complexitat','realitzat','consumat','explicat',
            // ES
            'mundo','mundano','manifestado','desplegado','despliegue','maduro',
            'integrado','complejo','complejidad','realizado','consumado','explicado',
        ],
    ];
}

/**
 * Compute implicit coverage ℑ — semantic touch detection per cardinal.
 *
 * Counts case-insensitive word-boundary matches of each term per cardinal.
 * Returns:
 *   ['coverage' => float[0,1], 'quadrants_touched' => list, 'distribution' => map]
 *
 * Distribution is normalized so the touched cardinals sum to 1.0 (relative
 * weight). Coverage is just n_touched / 8.
 */
function mg_implicit_coverage($text) {
    $cardinals = ['PLA','MON','SUB','OBJ','TEO','PRA','FEN','NOU'];
    $terms = mg_implicit_terms();
    $counts = array_fill_keys($cardinals, 0);
    if (!is_string($text) || $text === '') {
        return [
            'coverage' => 0.0,
            'quadrants_touched' => [],
            'distribution' => array_fill_keys($cardinals, 0.0),
        ];
    }
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($terms as $card => $term_list) {
        foreach ($term_list as $t) {
            // Word-boundary match — term must be a standalone word.
            // Use mb-aware regex with /u flag.
            $tq = preg_quote(mb_strtolower($t, 'UTF-8'), '/');
            // \b doesn't always work well with unicode chars; use lookarounds.
            $pat = '/(?<![\p{L}\p{N}_-])' . $tq . '(?![\p{L}\p{N}_-])/u';
            $n = @preg_match_all($pat, $lower);
            if ($n) $counts[$card] += $n;
        }
    }
    $touched = [];
    foreach ($cardinals as $c) {
        if ($counts[$c] > 0) $touched[] = $c;
    }
    $total = array_sum($counts);
    $dist = [];
    foreach ($cardinals as $c) {
        $dist[$c] = $total > 0 ? round($counts[$c] / $total, 4) : 0.0;
    }
    return [
        'coverage' => round(count($touched) / 8.0, 4),
        'quadrants_touched' => $touched,
        'distribution' => $dist,
    ];
}

/**
 * Mereological coverage 𝓜 — Xirinacs § 422 Part-Tot.
 *
 * Detects the four canonical Part-Whole relations of Xirinacs's § 422.01-§ 422.07,
 * each anchored at a diagonal meridian of the Meta-Globàlium:
 *
 *   - identity     A = A      "Cada part és ella mateixa"      LOG, CIE, TEC   Vigília
 *   - inclusion    A ⊂ T      "Cada part és en el tot"         IDE, MTF, ETI   Creença
 *   - containment  T ⊂ A      "El tot és en cada part"         MIT, MTP, MIS   Deliri
 *   - correlation  A ⊂ B      "Cada part és en les altres"     EST, ART, PSI   Somni
 *
 * Combines TWO signals:
 *   (a) Categorical: any of the 3 diagonal categories of a relation cited?
 *   (b) Lexical: characteristic multi-lingual phrases or roots in the text?
 *
 * A relation is "touched" if at least one of the two signals fires.
 * Score: count_touched / 4 in [0, 1].
 *
 * Use case: a response can score harmonic_score > 0.5 by touching 3 quadrants
 * yet remain mereologically poor (e.g. only operating on identity / definitions).
 * 𝓜 surfaces that bias.
 *
 * @param string $text  Response text (may be empty)
 * @param array  $codes Cited canonical codes (already filtered)
 * @return array shape:
 *   [
 *     'coverage' => float in [0,1],
 *     'count' => int 0..4,
 *     'relations_touched' => list of relation names,
 *     'signals' => per-relation [cat_hit => bool, lex_hit => bool, categories => [...], terms_matched => [...]],
 *     'consciousness_states' => list of consciousness states corresponding to touched relations,
 *   ]
 */
function mg_mereological_coverage($text, $codes) {
    $relations = [
        'identity' => [
            'categories' => ['LOG', 'CIE', 'TEC'],
            'lexical' => '/\b(?:cada\s+part\s+(?:és|es)\s+ella\s+mateixa|self[\-\s]identity|self[\-\s]sameness|tautolog[íiy]a?|auto[\-\s]?identita[ts]|autoidentity|autoidentidad|identitat\s+pr[oò]pia|cada\s+cosa\s+(?:és|es)\s+ella\s+mateixa|principi\s+d[\'\s]?identitat|principle\s+of\s+identity|principio\s+de\s+identidad|definici[óo]n?|categoritzar|classificaci[óo]n?|taxonomia|nom\s+propi|essential\s+nature|cosa\s+en\s+s[íi]|([A-Z]{1,4})\s*=\s*\1)\b/iu',
            'state' => 'Vigília',
        ],
        'inclusion' => [
            'categories' => ['IDE', 'MTF', 'ETI'],
            'lexical' => '/\b(?:cada\s+part\s+(?:és|es)\s+en\s+el\s+tot|part\s+(?:és|es)\s+al\s+tot|pertany(?:\s+al?\s+tot)?|belongs?\s+to\s+the\s+whole|pertenece\s+al\s+todo|universal(?:itat|idad|ity)?|necessitat|necesidad|necessity|inclusi[óo]n?|inclusion|principi\s+universal|deure|obligaci[óo]|imperatiu|categorical\s+imperative|llei\s+general|general\s+law|principi\s+de\s+necessitat|m[oó]ral)\b/iu',
            'state' => 'Creença',
        ],
        'containment' => [
            'categories' => ['MIT', 'MTP', 'MIS'],
            'lexical' => '/\b(?:el\s+tot\s+(?:és|es)\s+en\s+cada\s+part|el\s+tot\s+a\s+cada|whole\s+(?:is\s+)?in\s+(?:each|every)\s+part|todo\s+(?:est[áa]\s+)?en\s+cada\s+parte|holism[eo]?|hol[ií]stic[ao]?|hol[ií]sticament|hol[ií]stically|holism|microcosm[eo]?s?|fractal|s[eè]lf[\-\s]similar(?:ity)?|autosemblan[çc]a|isomorfism[eo]?|reflexi?[óo]n?\s+del\s+tot|encisament|enchantment|m[íi]stica?|mystic(?:al)?|mythic|m[íi]tic[ao]|principi\s+d[\'\s]?encisament|principle\s+of\s+enchantment)\b/iu',
            'state' => 'Deliri',
        ],
        'correlation' => [
            'categories' => ['EST', 'ART', 'PSI'],
            'lexical' => '/\b(?:cada\s+part\s+(?:és|es)\s+en\s+les\s+altres|in\s+the\s+other\s+parts?|en\s+las\s+otras\s+partes|alteritat|alterity|alteridad|relacional|relational|otherness|differ[èeé]nci?a|diff[eé]r[eaà]nce|otredad|interrelaci[óo]n?|interplay|joc|joc\s+entre|jugar\s+amb|game\s+of|principi\s+de\s+joc|principle\s+of\s+play|principio\s+de\s+juego|st[eé]ril[\-\s]man|interactuar(?:\s+amb)?|interact(?:\s+with)?|relacionar(?:\-se)?|en\s+relaci[óo]\s+amb)\b/iu',
            'state' => 'Somni',
        ],
    ];

    $signals = [];
    $touched = [];
    $states  = [];
    $codes_set = is_array($codes) ? array_flip($codes) : [];

    foreach ($relations as $rel_name => $cfg) {
        $cat_hit = false;
        $cat_matched = [];
        foreach ($cfg['categories'] as $cat) {
            if (isset($codes_set[$cat])) {
                $cat_hit = true;
                $cat_matched[] = $cat;
            }
        }

        $lex_hit = false;
        $terms_matched = [];
        if (is_string($text) && $text !== '' && preg_match_all($cfg['lexical'], $text, $m)) {
            $lex_hit = true;
            // dedupe lowercase
            $seen = [];
            foreach ($m[0] as $tt) {
                $lt = mb_strtolower($tt, 'UTF-8');
                if (!isset($seen[$lt])) { $seen[$lt] = true; $terms_matched[] = $tt; }
            }
        }

        $signals[$rel_name] = [
            'cat_hit'        => $cat_hit,
            'lex_hit'        => $lex_hit,
            'categories'     => $cat_matched,
            'terms_matched'  => array_slice($terms_matched, 0, 5),
        ];

        if ($cat_hit || $lex_hit) {
            $touched[] = $rel_name;
            $states[]  = $cfg['state'];
        }
    }

    $count = count($touched);
    return [
        'coverage'             => $count / 4.0,
        'count'                => $count,
        'relations_touched'    => $touched,
        'signals'              => $signals,
        'consciousness_states' => $states,
    ];
}

} // end function-exists guard
