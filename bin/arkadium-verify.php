#!/usr/bin/env php
<?php
/**
 * arkadium-verify.php — score a text with the PHP verifier used in production
 * at api.arkadium.ai (verifyResponse): 𝓗 with secondary quadrants, the 𝓦 v3
 * lexical wisdom score (8 components, incl. mereological coverage), implicit
 * coverage ℑ, factual density, voltes.
 *
 *   php bin/arkadium-verify.php [--json] [file ...]   (reads stdin if no file)
 *
 * Pure computation: no network, no model, no API key.
 */
require_once __DIR__ . '/../php/verifier.php';
require_once __DIR__ . '/../php/wisdom_score.php';

$args = array_slice($argv, 1);
$json = false; $files = [];
foreach ($args as $a) {
    if ($a === '--json') $json = true;
    elseif ($a === '-h' || $a === '--help') { echo "usage: arkadium-verify.php [--json] [file ...]\n"; exit(0); }
    else $files[] = $a;
}
$inputs = $files ? array_map(function ($f) { return [$f, file_get_contents($f)]; }, $files)
                 : [['stdin', stream_get_contents(STDIN)]];
$out = [];
foreach ($inputs as list($name, $text)) {
    $v = verifyResponse($text);
    unset($v['cleaned_text']);
    $out[] = ['input' => $name] + $v;
}
if ($json) {
    echo json_encode(count($out) === 1 ? $out[0] : $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($out as $r) {
        printf("%s\n  H = %.3f  (%d/8 quadrants, %d codes%s)\n  W = %.3f  (v3; mereological %.2f · factual density %.2f)\n",
            $r['input'], $r['harmonic_score'], $r['n_quadrants'], count($r['cited_categories']),
            $r['missing_quadrants'] ? ', missing ' . implode(' ', $r['missing_quadrants']) : '',
            $r['wisdom_score'], isset($r['mereological_coverage']) ? $r['mereological_coverage'] : 0, isset($r['factual_density']) ? $r['factual_density'] : 0);
    }
}
