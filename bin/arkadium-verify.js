#!/usr/bin/env node
/**
 * arkadium-verify — score a text with the Arkadium structural verifier.
 *
 *   arkadium-verify [--json] [--whitelist path] [file ...]
 *   cat response.txt | arkadium-verify
 *
 * Prints 𝓗 (harmonic completeness) and 𝓦 (lexical wisdom score) for each
 * input. Pure computation: no network, no model, no API key.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', 'js');
require(path.join(root, 'verifier.js'));
require(path.join(root, 'wisdom.js'));

const args = process.argv.slice(2);
let asJson = false;
let wlPath = path.join(root, 'whitelist.json');
const files = [];
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--json') asJson = true;
  else if (a === '--whitelist') wlPath = args[++i];
  else if (a === '-h' || a === '--help') {
    console.log('usage: arkadium-verify [--json] [--whitelist path] [file ...]  (reads stdin if no file)');
    process.exit(0);
  } else files.push(a);
}
const whitelist = JSON.parse(fs.readFileSync(wlPath, 'utf8'));

function scoreText(name, text) {
  const v = ArkadiumVerifier.verify(text, whitelist);
  const w = ArkadiumWisdom.wisdomScore(text, whitelist);
  return { input: name, harmonic: v, wisdom: w };
}

const inputs = files.length
  ? files.map(f => [f, fs.readFileSync(f, 'utf8')])
  : [['stdin', fs.readFileSync(0, 'utf8')]];

const results = inputs.map(([n, t]) => scoreText(n, t));

if (asJson) {
  console.log(JSON.stringify(results.length === 1 ? results[0] : results, null, 2));
} else {
  for (const r of results) {
    const v = r.harmonic, w = r.wisdom;
    console.log(`${r.input}`);
    console.log(`  H = ${v.harmonic_score.toFixed(3)}  (${v.n_quadrants}/8 quadrants, ${v.total_citations} codes${v.missing_quadrants.length ? ', missing ' + v.missing_quadrants.join(' ') : ''})`);
    console.log(`  W = ${w.wisdom_score.toFixed(3)}  cov ${w.coverage.toFixed(2)} · ent ${w.entropy_normalized.toFixed(2)} · pair ${w.dialectical_pair_density.toFixed(2)} · tension ${w.tension_density.toFixed(2)} · synth ${w.synthesis_anchoring.toFixed(2)} · axis ${w.axis_explicit.toFixed(2)} · subord ${w.subordinating_synthesis.toFixed(2)}`);
  }
}
