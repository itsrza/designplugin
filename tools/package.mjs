#!/usr/bin/env node
/**
 * Builds the installable WordPress zip from the plugin folder.
 *
 *   node tools/package.mjs
 *   → release/miran-<version>.zip   (upload this in wp-admin → Plugins → Add New)
 *
 * The version is read from the plugin header so the zip name always matches
 * what WordPress shows. Release artefacts stay out of git (see .gitignore).
 */
import { mkdir, readFile, rm, stat } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PLUGIN_DIR = 'miran';
const RELEASE_DIR = path.join(ROOT, 'release');

const header = await readFile(path.join(ROOT, PLUGIN_DIR, 'miran.php'), 'utf8');
const version = (header.match(/^\s*\*\s*Version:\s*(.+)$/m) || [])[1];
if (!version) {
  console.error('Could not read the "Version:" header from miran/miran.php');
  process.exit(1);
}

const zipName = `${PLUGIN_DIR}-${version.trim()}.zip`;
const zipPath = path.join(RELEASE_DIR, zipName);

await mkdir(RELEASE_DIR, { recursive: true });
await rm(zipPath, { force: true });

const zip = spawnSync(
  'zip',
  ['-r', '-q', zipPath, PLUGIN_DIR, '-x', '*.DS_Store', '-x', '*/.git/*'],
  { cwd: ROOT, stdio: 'inherit' }
);

if (zip.status !== 0) {
  console.error('zip failed (is the "zip" binary installed?)');
  process.exit(zip.status || 1);
}

const { size } = await stat(zipPath);
console.log(`\n✓ ${path.relative(ROOT, zipPath)} (${(size / 1024).toFixed(1)} kB) · version ${version.trim()}`);
