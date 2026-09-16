# arkadium-verifier

The structural verifier of the [Arkadium](https://arkadium.ai) project, as a standalone package you can run on your own texts, on your own machine, with no model call and no API key.

It computes two numbers for a text that cites the canonical codes of the Meta-Globàlium (`SUB`, `OBJ`, `TEO`, `PRA`, `FEN`, `NOU`, `PLA`, `MON` and the 80 categories that hang from them):

- **𝓗, harmonic completeness** — how many of the eight poles the text touches and how evenly. `𝓗 = 0.5·(n_quadrants/8) + 0.5·(entropy/log 8)`.
- **𝓦, wisdom score** — a *lexical* indicator of whether the poles are related dialectically or merely listed: both poles of an axis in the same paragraph, tension markers ("however", "yet", "però"…) near two codes, mediators (`SIN`, `AMO`…) cited with the poles they integrate, an explicit axis in the opening, subordinating verbs.

Both are pure code: regular expressions and arithmetic. They are the same code that runs in the browser at [arkadium.ai/demo](https://arkadium.ai/demo/) and on the server at api.arkadium.ai. The design is explained in the [Arkadium paper](https://arkadium.ai/papers/arkadium/en/) (§4, §4.bis, §9.5).

## What it is not

Read this before using the numbers.

- **𝓦 does not read meaning.** It measures the *shape* of a dialectical answer. A fluent template that reproduces the surface features scores 0.96 while saying nothing about the question; the genuine answer to the same question scores 0.95. That text is in `examples/adversarial-fluent.txt`, and the demo shows it on purpose (paper §4.bis, fourth Goodhart cycle). Use 𝓦 as a cheap first filter and as a signal against list-form answers, not as a judge.
- **𝓗 needs explicit codes.** A text that does not cite the canonical codes scores 0 whatever it says (see `examples/q1-bare.txt`). The PHP implementation adds an *implicit coverage* ℑ estimated from natural-language terms, as a diagnostic complement only.
- **Neither number is a validated measure of response quality.** The correlation with human judgment is the subject of a study that has not been run yet (paper §9.3.b). Treat the scores as structural properties of the text, which is what they are.

## Two implementations, not one

| | `js/` (browser, Node) | `php/` (production server) |
|---|---|---|
| 𝓗 | primary quadrant of each code | primary quadrant + 0.5 to a secondary quadrant for the 24 codes that sit near an axis |
| 𝓦 | v2: 7 components | v3: 8 components, adds `mereological_coverage` (weight 0.15) and reweights three others |
| Extras | — | implicit coverage ℑ, factual density, causal and epistemic coverage, voltes, tempeternal balance |
| Where it runs | arkadium.ai/demo | api.arkadium.ai |

The two therefore give slightly different numbers for the same text (for `examples/q1-dialectical.txt`: 𝓦 0.947 in JS, 0.834 in PHP). Both sets are pinned by the tests. If you need one number, say which implementation you used.

## Usage

Node (no dependencies):

```bash
node bin/arkadium-verify.js examples/q1-dialectical.txt
node bin/arkadium-verify.js --json examples/adversarial-fluent.txt
cat my-response.txt | node bin/arkadium-verify.js
```

PHP (7.2 or later, no extensions beyond `json` and `mbstring`):

```bash
php bin/arkadium-verify.php examples/q1-dialectical.txt
php bin/arkadium-verify.php --json my-response.txt
```

In your own JavaScript:

```js
require('./js/verifier.js');   // defines global ArkadiumVerifier
require('./js/wisdom.js');     // defines global ArkadiumWisdom
const whitelist = require('./js/whitelist.json');   // code → quadrant
const h = ArkadiumVerifier.verify(text, whitelist);       // { harmonic_score, n_quadrants, quadrant_counts, ... }
const w = ArkadiumWisdom.wisdomScore(text, whitelist);    // { wisdom_score, dialectical_pair_density, ..., detail }
```

In your own PHP:

```php
require 'php/verifier.php';
require 'php/wisdom_score.php';
$r = verifyResponse($text);   // harmonic_score, wisdom_score, mereological_coverage, implicit_coverage, ...
```

Tests (golden master over the five examples, both implementations):

```bash
npm test          # = node test/run.js && php test/run.php
```

## Examples

| File | What it is | 𝓗 (JS) | 𝓦 (JS) |
|---|---|---|---|
| `q1-bare.txt` | Claude with no anchoring, no codes | 0.000 | 0.000 |
| `q1-list.txt` | the eight poles enumerated, one paragraph each | 0.969 | 0.667 |
| `q1-dialectical.txt` | tension before synthesis, mediators anchored | 0.965 | 0.947 |
| `adversarial-naive.txt` | *lorem ipsum* under eight codes: caught by 𝓦 | 0.965 | 0.097 |
| `adversarial-fluent.txt` | the dialectical form with no content: **not caught** | 0.965 | 0.959 |

The question behind Q1 is "Should we limit speeds to 30 km/h citywide?".

## What is in the package and what is not

In: the two verifier implementations, the code-to-quadrant map (`js/whitelist.json`, `php/data/categories_codes.json`: labels, names, render coordinates and quadrant of the 91 codes; no category texts), five example texts, tests.

Not in: the Arkadium agent (RAG, system prompt, re-prompt loop), the 3D Metamodeler, the category texts, and the wisdom-benchmark scorer. The scorer calls a language model and would cost tokens to whoever hosts it; it will be published as code to run with your own key, not as a public endpoint.

The ontology itself (the 80 categories, their definitions and coordinates) is published separately under CC BY-SA 4.0 at [opengea/meta-globalium](https://github.com/opengea/meta-globalium).

## Citation

Berenguer, J. (2026). *Arkadium: a neuro-symbolic agent anchored to the Meta-Globàlium for structural verification of human judgment in artificial intelligence systems*, version 1.12. Opengea SCCL. https://doi.org/10.5281/zenodo.20024451

## License

Apache License 2.0. See `LICENSE` and `NOTICE`.
