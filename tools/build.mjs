#!/usr/bin/env node
/**
 * Minifies the plugin assets.
 *
 *   node tools/build.mjs          # (re)write assets/css/*.min.css and assets/js/*.min.js
 *   node tools/build.mjs --check  # only report whether the .min files are up to date
 *
 * Rules:
 *  - only "*.min.css" / "*.min.js" files are ever written;
 *  - the readable sources stay the single source of truth;
 *  - CSS uses CleanCSS level 1 (no selector merging / structural rewrites),
 *    JS uses Terser with ES5 output because the plugin targets old browsers.
 */
import { readdir, readFile, writeFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import CleanCSS from 'clean-css';
import { minify as minifyJs } from 'terser';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = path.join(ROOT, 'miran', 'assets');
const CHECK_ONLY = process.argv.includes('--check');

const BANNER = (name) => `/*! Miran — ${name} (minified build, do not edit; run "npm run build") */\n`;

const SOURCES = [
  { dir: 'css', ext: '.css', kind: 'css' },
  { dir: 'js', ext: '.js', kind: 'js' },
];

function human(bytes) {
  return `${(bytes / 1024).toFixed(1)} kB`;
}

async function minifyCss(text, file) {
  const result = new CleanCSS({
    level: 1,
    compatibility: 'ie11',
    format: 'keep-breaks',
  }).minify({ [file]: { styles: text } });

  if (result.errors && result.errors.length) {
    throw new Error(`clean-css: ${result.errors.join('; ')}`);
  }
  return result.styles;
}

async function minifyJavaScript(text, file) {
  const result = await minifyJs(text, {
    ecma: 5,
    compress: true,
    mangle: true,
    format: { comments: false },
    sourceMap: false,
  });
  if (!result.code) {
    throw new Error(`terser produced no output for ${file}`);
  }
  return result.code;
}

async function processDir({ dir, ext, kind }) {
  const absolute = path.join(ASSETS, dir);
  const entries = (await readdir(absolute)).filter((name) => name.endsWith(ext) && !name.endsWith(`.min${ext}`));
  let stale = 0;

  for (const name of entries.sort()) {
    const sourcePath = path.join(absolute, name);
    const targetPath = path.join(absolute, name.replace(new RegExp(`\\${ext}$`), `.min${ext}`));
    const source = await readFile(sourcePath, 'utf8');
    const label = path.relative(ROOT, sourcePath);

    const minified = kind === 'css' ? await minifyCss(source, label) : await minifyJavaScript(source, label);
    const output = BANNER(name) + minified.trim() + '\n';

    let previous = null;
    try {
      previous = await readFile(targetPath, 'utf8');
    } catch {
      previous = null;
    }

    const sourceSize = (await stat(sourcePath)).size;

    if (previous === output) {
      console.log(`  = ${label} → ${path.basename(targetPath)} (${human(sourceSize)} → ${human(output.length)})`);
      continue;
    }

    stale += 1;

    if (CHECK_ONLY) {
      console.log(`  ! ${label} → ${path.basename(targetPath)} is out of date`);
      continue;
    }

    await writeFile(targetPath, output, 'utf8');
    console.log(`  ↻ ${label} → ${path.basename(targetPath)} (${human(sourceSize)} → ${human(output.length)})`);
  }

  return stale;
}

const total = {};
for (const entry of SOURCES) {
  console.log(`${entry.dir.toUpperCase()}:`);
  total[entry.dir] = await processDir(entry);
}

if (CHECK_ONLY) {
  const staleTotal = Object.values(total).reduce((sum, value) => sum + value, 0);
  if (staleTotal > 0) {
    console.error(`\n${staleTotal} minified file(s) out of date — run "npm run build".`);
    process.exit(1);
  }
  console.log('\nAll minified assets are up to date.');
}
