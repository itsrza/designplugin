#!/usr/bin/env node
/**
 * Syntax check for the PHP sources without a PHP binary.
 *
 *   node tools/lint-php.mjs
 *
 * Parses every *.php file in the plugin with php-parser (PHP 8 grammar).
 * This is NOT a replacement for `php -l` (no runtime checks, no PHPCS), but it
 * catches the mistakes that actually break a site: unbalanced braces, missing
 * semicolons, broken heredocs, stray closing tags, etc.
 */
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import engine from 'php-parser';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PLUGIN_DIR = path.join(ROOT, 'serviya');
const parser = new engine({
  parser: { extractDoc: false, suppressErrors: false },
  ast: { withPositions: true },
});

async function collect(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...(await collect(full)));
    } else if (entry.name.endsWith('.php')) {
      files.push(full);
    }
  }
  return files;
}

const files = (await collect(PLUGIN_DIR)).sort();
let failed = 0;

for (const file of files) {
  const relative = path.relative(ROOT, file);
  const source = await readFile(file, 'utf8');
  try {
    parser.parseCode(source, relative);
    console.log(`  ✓ ${relative}`);
  } catch (error) {
    failed += 1;
    const line = error?.lineNumber ?? error?.loc?.start?.line ?? '?';
    console.error(`  ✗ ${relative}:${line} — ${error.message}`);
  }
}

if (failed > 0) {
  console.error(`\n${failed} file(s) failed to parse.`);
  process.exit(1);
}

console.log(`\nAll ${files.length} PHP file(s) parsed successfully.`);
