/**
 * verifier.js — client-side port of api/verifier.php::verifyResponse()
 *
 * Same formula as the deployed Arkadium verifier:
 *   coverage           = n_quadrants / 8
 *   normalized_entropy = -sum(p log p) / log 8
 *   harmonic_score (𝓗) = 0.5 · coverage + 0.5 · normalized_entropy
 *
 * Input: response text + whitelist map { code → quadrant }
 * Output: { harmonic_score, n_quadrants, quadrants_touched, quadrant_counts,
 *           cited_categories, missing_quadrants, entropy, normalized_entropy }
 */
(function (global) {
  'use strict';

  const QUADRANTS = ['PLA', 'MON', 'SUB', 'OBJ', 'TEO', 'PRA', 'FEN', 'NOU'];

  function extractCodes(text) {
    if (typeof text !== 'string' || text === '') return [];
    const matches = text.matchAll(/\b[A-Z]{2,4}[0-9]?\b/g);
    const seen = new Set();
    for (const m of matches) seen.add(m[0]);
    return [...seen];
  }

  function verify(text, whitelist) {
    const cited = extractCodes(text).filter(c => whitelist[c] !== undefined);
    const counts = Object.fromEntries(QUADRANTS.map(q => [q, 0]));
    cited.forEach(c => { counts[whitelist[c]] += 1; });

    const total = cited.length;
    const touched = QUADRANTS.filter(q => counts[q] > 0);
    const missing = QUADRANTS.filter(q => counts[q] === 0);
    const n = touched.length;

    let entropy = 0;
    if (total > 0) {
      QUADRANTS.forEach(q => {
        if (counts[q] > 0) {
          const p = counts[q] / total;
          entropy -= p * Math.log(p);
        }
      });
    }
    const normEntropy = entropy / Math.log(8);
    const coverage = n / 8;
    const harmonic = 0.5 * coverage + 0.5 * normEntropy;

    return {
      cited_categories: cited,
      quadrants_touched: touched,
      quadrant_counts: counts,
      n_quadrants: n,
      missing_quadrants: missing,
      entropy: Number(entropy.toFixed(6)),
      normalized_entropy: Number(normEntropy.toFixed(6)),
      harmonic_score: Number(harmonic.toFixed(6)),
      needs_reprompt: n < 3,
      total_citations: total
    };
  }

  global.ArkadiumVerifier = { verify, extractCodes, QUADRANTS };
})(typeof window !== 'undefined' ? window : globalThis);
