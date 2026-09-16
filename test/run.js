'use strict';
// Golden-master test for the browser/Node implementation (𝓦 v2, primary quadrants).
const { execFileSync } = require('child_process');
const path = require('path');
const expected = require('./expected-js.json');
let fail = 0;
for (const [file, exp] of Object.entries(expected)) {
  const out = JSON.parse(execFileSync('node', [path.join(__dirname, '..', 'bin', 'arkadium-verify.js'), '--json', path.join(__dirname, '..', 'examples', file)], { encoding: 'utf8' }));
  const got = { H: out.harmonic.harmonic_score, W: out.wisdom.wisdom_score };
  const ok = Math.abs(got.H - exp.H) < 1e-6 && Math.abs(got.W - exp.W) < 1e-6;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${file}  H=${got.H} W=${got.W}` + (ok ? '' : `  expected H=${exp.H} W=${exp.W}`));
  if (!ok) fail++;
}
process.exit(fail ? 1 : 0);
