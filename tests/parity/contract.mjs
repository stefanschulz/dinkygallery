#!/usr/bin/env node
/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 *
 * The markup contract check.
 *
 * The rendered HTML, the stylesheet and the ES module share a vocabulary: the `dg-*`
 * class names, the `--dg-*` custom properties, and the `data-*` attributes the server
 * puts on `.dg` for the script to read. Rename one of those in a single file and
 * nothing breaks loudly — a card just loses its styling, or the lightbox stops sizing.
 * This asserts the three files still agree.
 *
 *   node tests/parity/contract.mjs
 *
 * DinkyMetrics pins its server and browser number formatting to each other the same
 * way (tests/parity/parity.mjs); this is the equivalent for a plugin whose contract is
 * markup rather than a formatted string.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (rel) => readFileSync(join(root, rel), 'utf8');

const render = read('src/Helper/Render.php');
const css    = read('media/plg_content_dinkygallery/css/dinkygallery.css');
const js     = read('media/plg_content_dinkygallery/js/dinkygallery.js');

const uniq = (a) => [...new Set(a)].sort();
const matches = (s, re) => uniq([...s.matchAll(re)].map((m) => m[1] ?? m[0]));

let failed = 0;

/**
 * Compare two token sets and report what only one side has.
 *
 * @param {string}   label
 * @param {string[]} expected  the set the others are checked against
 * @param {string[]} actual
 * @param {string}   expectedName
 * @param {string}   actualName
 */
const compare = (label, expected, actual, expectedName, actualName) => {
  const exp = new Set(expected);
  const act = new Set(actual);
  const missing = [...exp].filter((t) => !act.has(t));
  const extra   = [...act].filter((t) => !exp.has(t));

  if (missing.length === 0 && extra.length === 0) {
    console.log(`  ok    ${label} (${exp.size})`);

    return;
  }

  failed++;
  console.error(`  FAIL  ${label}`);

  if (missing.length) {
    console.error(`        in ${expectedName} but not ${actualName}: ${missing.join(', ')}`);
  }

  if (extra.length) {
    console.error(`        in ${actualName} but not ${expectedName}: ${extra.join(', ')}`);
  }
};

console.log('Markup contract — Render.php <-> dinkygallery.css <-> dinkygallery.js\n');

// --- classes ---------------------------------------------------------------
// CSS is the reference: every `.dg*` selector it styles must be produced by the
// server markup or built by the script, and vice versa.
const cssClasses = matches(css, /\.(dg[\w-]*)/g);

const renderClasses = uniq(
  [...render.matchAll(/class="([^"]+)"/g)]
    .flatMap((m) => m[1].split(/\s+/))
    .filter((t) => /^dg[\w-]*$/.test(t))
);

// The script only ever names the lightbox classes it owns plus a known handful of
// carousel classes it queries. The `(?<![\w-])` keeps `--dg-lb-rgb` and
// `data-dg-close` out.
const jsClasses = matches(js, /(?<![\w-])(dg-(?:lb|card|track|arrow|count)[\w-]*)/g);

compare('dg-* classes', cssClasses, uniq([...renderClasses, ...jsClasses]), 'CSS', 'Render+JS');

// --- custom properties ---------------------------------------------------
const cssProps    = matches(css, /(--dg-[\w-]+)/g);
const renderProps = matches(render, /(--dg-[\w-]+)/g);
const jsProps     = matches(js, /(--dg-[\w-]+)/g);

compare('--dg-* custom properties', cssProps, uniq([...renderProps, ...jsProps]), 'CSS', 'Render+JS');

// The server sets four; the script sets the rest. No property belongs to both.
const overlap = renderProps.filter((p) => jsProps.includes(p));

if (overlap.length) {
  failed++;
  console.error(`  FAIL  --dg-* written by both Render and JS: ${overlap.join(', ')}`);
} else {
  console.log(`  ok    --dg-* split cleanly between Render (${renderProps.length}) and JS (${jsProps.length})`);
}

// --- data-* handoff ----------------------------------------------------
// Each data-* the server writes onto an element must be read by the script.
const EMIT_ONLY = new Set(['data-cards']); // informational; the script measures real widths

const renderData = matches(render, /(data-[\w-]+)="/g).filter((d) => !EMIT_ONLY.has(d));
const camel = (d) => d.replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
const unread = renderData.filter((d) => !js.includes(`dataset.${camel(d)}`) && !js.includes(d));

if (unread.length === 0) {
  console.log(`  ok    data-* handoff (${renderData.length} read by the script)`);
} else {
  failed++;
  console.error(`  FAIL  data-* written by Render but never read by JS: ${unread.join(', ')}`);
}

console.log(failed === 0 ? '\nContract intact.' : `\n${failed} check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);
