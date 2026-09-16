<?php
// Golden-master test for the production PHP implementation (𝓦 v3, secondary quadrants).
$expected = json_decode(file_get_contents(__DIR__ . '/expected-php.json'), true);
require_once __DIR__ . '/../php/verifier.php';
require_once __DIR__ . '/../php/wisdom_score.php';
$fail = 0;
foreach ($expected as $file => $exp) {
    $v = verifyResponse(file_get_contents(__DIR__ . '/../examples/' . $file));
    $ok = abs($v['harmonic_score'] - $exp['H']) < 1e-6 && abs($v['wisdom_score'] - $exp['W']) < 1e-6;
    printf("%s %s  H=%s W=%s%s\n", $ok ? 'ok  ' : 'FAIL', $file, $v['harmonic_score'], $v['wisdom_score'], $ok ? '' : "  expected H={$exp['H']} W={$exp['W']}");
    if (!$ok) $fail++;
}
exit($fail ? 1 : 0);
