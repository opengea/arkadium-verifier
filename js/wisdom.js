/**
 * wisdom.js — client-side port of api/wisdom_score.php  (v2)
 *
 * v2 formula:
 *   𝓦 = 0.05·coverage + 0.05·entropy_normalized
 *     + 0.20·dialectical_pair_density + 0.20·tension_density
 *     + 0.15·synthesis_anchoring + 0.15·axis_explicit
 *     + 0.20·subordinating_synthesis
 *
 * Numerical results must match the PHP server-side function bit-for-bit
 * (within rounding) for the same input.
 */
(function (global) {
  'use strict';

  const QUADRANTS = ['PLA', 'MON', 'SUB', 'OBJ', 'TEO', 'PRA', 'FEN', 'NOU'];

  const DIALECTICAL_AXES = [
    ['OBJ', 'SUB'],
    ['TEO', 'PRA'],
    ['NOU', 'FEN'],
    ['PLA', 'MON'],
  ];

  const TENSION_MARKERS = [
    'yet','however','but','while','although','in tension with','despite',
    'contrary to','on the other hand','rather than','whereas','nonetheless','still',
    'però','tot i que','tanmateix','no obstant','en canvi','mentre que',
    "d'altra banda",'al contrari','ans'
    // Spanish dropped 2026-05-08 per project decision — CA + EN only.
  ];

  // Mediator anchoring requirements per cicle. '__one_other__' = needs at
  // least one more code beyond the explicit requirements.
  // NB: internal `volta:` keys preserved for Fase B (estructural rename).
  const MEDIATOR_ANCHORING = {
    // Aplicació
    ANA: { volta: 'aplicacio',   requires: ['FEN'] },
    SIN: { volta: 'aplicacio',   requires: ['TEO', '__one_other__'] },
    AMO: { volta: 'aplicacio',   requires: ['NOU', 'PRA'] },
    EXP: { volta: 'aplicacio',   requires: ['PRA', 'FEN'] },
    // Orientació
    STM: { volta: 'orientacio',  requires: ['SUB', 'PRA'] },
    STT: { volta: 'orientacio',  requires: ['SUB', 'TEO'] },
    SGT: { volta: 'orientacio',  requires: ['TEO', 'OBJ'] },
    SGE: { volta: 'orientacio',  requires: ['OBJ', 'PRA'] },
    // Coneixement
    ART: { volta: 'coneixement', requires: ['FEN', 'SUB'] },
    MTP: { volta: 'coneixement', requires: ['SUB', 'NOU'] },
    MTF: { volta: 'coneixement', requires: ['NOU', 'OBJ'] },
    CIE: { volta: 'coneixement', requires: ['OBJ', 'FEN'] },
  };

  function extractCodes(text, whitelist) {
    if (typeof text !== 'string' || text === '') return [];
    const matches = [...text.matchAll(/\b[A-Z]{2,4}[0-9]?\b/g)];
    const seen = new Set();
    matches.forEach(m => {
      if (!whitelist || whitelist[m[0]] !== undefined) seen.add(m[0]);
    });
    return [...seen];
  }

  function paragraphsOf(text) {
    return text.split(/\n\s*\n+/).map(s => s.trim()).filter(s => s.length > 0);
  }

  function codesPerParagraph(text, whitelist) {
    return paragraphsOf(text).map(p => ({
      text: p,
      codes: extractCodes(p, whitelist),
    }));
  }

  function coverageEntropy(citedCodes, whitelist) {
    const counts = Object.fromEntries(QUADRANTS.map(q => [q, 0]));
    citedCodes.forEach(c => {
      const q = whitelist[c];
      if (q !== undefined) counts[q] += 1;
    });
    const total = QUADRANTS.reduce((s, q) => s + counts[q], 0);
    const touched = QUADRANTS.filter(q => counts[q] > 0);
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
    const normEntropy = total > 0 ? entropy / Math.log(8) : 0;
    return {
      coverage: n / 8,
      entropy_normalized: normEntropy,
      cited_quadrants: touched,
    };
  }

  function dialecticalPairs(text, whitelist) {
    const paras = codesPerParagraph(text, whitelist);
    const paraQuadrants = paras.map(p => {
      const qs = new Set();
      p.codes.forEach(c => { if (whitelist[c]) qs.add(whitelist[c]); });
      return [...qs];
    });

    const pairedAxes = [];
    DIALECTICAL_AXES.forEach(axis => {
      const [a, b] = axis;
      let paired = false;
      for (const qs of paraQuadrants) {
        if (qs.includes(a) && qs.includes(b)) { paired = true; break; }
      }
      if (paired) pairedAxes.push(axis);
    });
    return {
      dialectical_pair_density: pairedAxes.length / 4,
      paired_axes: pairedAxes,
    };
  }

  function tensionDensity(text, whitelist) {
    const events = [];
    const lower = text.toLowerCase();
    const codePos = [];
    [...text.matchAll(/\b[A-Z]{2,4}[0-9]?\b/g)].forEach(m => {
      const code = m[0];
      const q = whitelist[code];
      if (q !== undefined) codePos.push({ code, pos: m.index, quad: q });
    });
    if (codePos.length < 2) return { tension_density: 0.0, tension_events: [] };

    TENSION_MARKERS.forEach(marker => {
      const m = marker.toLowerCase();
      let off = 0;
      while (true) {
        const idx = lower.indexOf(m, off);
        if (idx === -1) break;
        const nearby = codePos.filter(c => Math.abs(c.pos - idx) <= 80);
        const quads = [...new Set(nearby.map(c => c.quad))];
        if (quads.length >= 2) {
          events.push({
            marker,
            codes: [...new Set(nearby.map(c => c.code))],
            quadrants: quads,
          });
        }
        off = idx + marker.length;
      }
    });
    return {
      tension_density: Math.min(1.0, events.length / 4),
      tension_events: events,
    };
  }

  function synthesisAnchoring(text, whitelist) {
    const paras = codesPerParagraph(text, whitelist);
    const status = {};
    const voltesActive = new Set();

    Object.entries(MEDIATOR_ANCHORING).forEach(([med, spec]) => {
      let present = false;
      let anchored = false;
      for (const p of paras) {
        if (!p.codes.includes(med)) continue;
        present = true;
        const needsOneOther = spec.requires.includes('__one_other__');
        const reqConcrete = spec.requires.filter(r => r !== '__one_other__');
        let allReq = reqConcrete.every(r => p.codes.includes(r));
        if (allReq && needsOneOther) {
          const other = p.codes.filter(c =>
            !reqConcrete.includes(c) && c !== med
          );
          if (other.length === 0) allReq = false;
        }
        if (allReq) {
          anchored = true;
          voltesActive.add(spec.volta);
          break;
        }
      }
      if (present) status[med] = anchored;
    });

    const mediators = Object.keys(status);
    if (mediators.length === 0) {
      return {
        synthesis_anchoring: 0.0,
        mediator_anchored: {},
        voltes_active: [],
      };
    }
    const anchored = mediators.filter(m => status[m]).length;
    return {
      synthesis_anchoring: anchored / mediators.length,
      mediator_anchored: status,
      voltes_active: [...voltesActive],
    };
  }

  // v2 component: explicit dialectical-axis framing in the opening (~600 chars)
  function axisExplicit(text) {
    const opening = text.slice(0, 600);
    const cards = '(?:OBJ|SUB|TEO|PRA|FEN|NOU|PLA|MON)';
    const patterns = [
      new RegExp('\\b' + cards + '\\s*(?:↔|--?|–|—|\\/)\\s*' + cards + '\\b', 'u'),
      new RegExp('\\b' + cards + '[\\s\\-]+' + cards + '\\s+axis', 'u'),
      new RegExp('\\baxis\\s*[:\\-]?\\s*(?:between|of|on|over|do)?[^\\n]{0,140}\\b' + cards, 'u'),
      new RegExp('\\btension\\s+(?:between|in|across)[^\\n]{0,140}\\b' + cards, 'u'),
      /\bsits?\s+(?:on|at|in)\s+(?:an?|the)\s+[^\n]{0,80}\baxis\b/u,
      /\bpresents?\s+(?:an?|the)\s+(?:axis|tension|polarity|dialectic)/u,
      /\btwo\s+(?:readings|views|frames|ways|stances|paradigms|perspectives|horns)\b/u,
      /\bcompeting\s+(?:readings|views|frames|paradigms|perspectives|positions|claims)\b/u,
    ];
    for (const pat of patterns) {
      if (pat.test(opening)) return 1.0;
    }
    return 0.0;
  }

  // v2 component: counts active mediation patterns. A subordinating verb (subsumes,
  // reframes, foregrounds, integrates, treats X as Y, ...) signals one frame doing
  // something to another, not just being listed alongside it. Saturates at 3.
  function subordinatingSynthesis(text) {
    const strong = /(subsume[sd]?|reframe[sd]?|reframing|foreground[sd]?|dissolve[sd]?|integrat(?:e[sd]?|ing)|reconcil(?:e[sd]?|ing)|absorb[sd]?|encompass(?:e[sd]?)?|enable[sd]?|articulat(?:e[sd]?|ing)|press(?:es)?\s+harder|make[sd]?\s+possible|treats?\s+\w+\s+as|preserve[sd]?\s+\w+\s+(?:while|by|without)|answer[sd]?\s+the|address(?:e[sd]?)?\s+the|compose[sd]?\s+across|coexist\s+as)/giu;
    let count = (text.match(strong) || []).length;
    // Chained attribution: "X (existentialist) Y (relational) Z (Stoic)"
    const chained = /\([a-z]+(?:ist|ic|al|ian|ean)?\)[^()]{1,80}\([a-z]+(?:ist|ic|al|ian|ean)?\)[^()]{1,80}\([a-z]+(?:ist|ic|al|ian|ean)?\)/giu;
    count += (text.match(chained) || []).length;
    return Math.min(1.0, count / 3.0);
  }

  function wisdomScore(text, whitelist) {
    if (typeof text !== 'string' || text === '' || !whitelist) {
      return {
        coverage: 0, entropy_normalized: 0,
        dialectical_pair_density: 0, tension_density: 0,
        synthesis_anchoring: 0,
        axis_explicit: 0, subordinating_synthesis: 0,
        wisdom_score: 0,
        detail: {
          paired_axes: [], tension_events: [],
          mediator_anchored: {}, cited_quadrants: [],
          voltes_active: [],
        },
      };
    }
    const cited = extractCodes(text, whitelist);
    const ce = coverageEntropy(cited, whitelist);
    const dp = dialecticalPairs(text, whitelist);
    const td = tensionDensity(text, whitelist);
    const sa = synthesisAnchoring(text, whitelist);
    const ax = axisExplicit(text);
    const ss = subordinatingSynthesis(text);

    const w =
        0.05 * ce.coverage
      + 0.05 * ce.entropy_normalized
      + 0.20 * dp.dialectical_pair_density
      + 0.20 * td.tension_density
      + 0.15 * sa.synthesis_anchoring
      + 0.15 * ax
      + 0.20 * ss;

    const round6 = x => Number(x.toFixed(6));
    return {
      coverage: round6(ce.coverage),
      entropy_normalized: round6(ce.entropy_normalized),
      dialectical_pair_density: round6(dp.dialectical_pair_density),
      tension_density: round6(td.tension_density),
      synthesis_anchoring: round6(sa.synthesis_anchoring),
      axis_explicit: round6(ax),
      subordinating_synthesis: round6(ss),
      wisdom_score: round6(w),
      detail: {
        paired_axes: dp.paired_axes,
        tension_events: td.tension_events,
        mediator_anchored: sa.mediator_anchored,
        cited_quadrants: ce.cited_quadrants,
        voltes_active: sa.voltes_active,
      },
    };
  }

  global.ArkadiumWisdom = {
    wisdomScore,
    QUADRANTS, DIALECTICAL_AXES, MEDIATOR_ANCHORING,
  };
})(typeof window !== 'undefined' ? window : globalThis);
