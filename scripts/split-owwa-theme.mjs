/**
 * Split owwa-theme backup into contiguous partials (preserves cascade order).
 * Run: node scripts/split-owwa-theme.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const sourcePath = path.join(root, 'public/css/filament/admin/owwa-theme.css.pre-split.bak');
const outDir = path.join(root, 'resources/css/owwa');

/**
 * Contiguous ascending slices — order == cascade order == build order.
 * Login/auth rules (~1771+) remain inside _charts.css so cascade is unchanged.
 */
const ORDERED_SLICES = [
    { file: '_tokens.css', startLine: 1 },
    { file: '_base.css', startLine: 24 },
    { file: '_dashboard.css', startLine: 225 },
    { file: '_forms.css', startLine: 521 },
    { file: '_tables.css', startLine: 1192 },
    { file: '_charts.css', startLine: 1229 },
    { file: '_pages.css', startLine: 3068 },
    { file: '_modals.css', startLine: 7054 },
    { file: '_misc.css', startLine: 10233 },
];

const text = fs.readFileSync(sourcePath, 'utf8');
const lines = text.split(/\r?\n/);

fs.mkdirSync(outDir, { recursive: true });

let covered = 0;
for (let i = 0; i < ORDERED_SLICES.length; i++) {
    const { file, startLine } = ORDERED_SLICES[i];
    const endLine = i + 1 < ORDERED_SLICES.length ? ORDERED_SLICES[i + 1].startLine - 1 : lines.length;
    const sliceLines = lines.slice(startLine - 1, endLine);
    covered += sliceLines.length;
    const header = `/* OWWA theme partial: ${file} (original lines ${startLine}-${endLine}) — edit then run: npm run build:owwa-theme */\n\n`;
    const body = sliceLines.join('\n').replace(/\n+$/, '\n');
    fs.writeFileSync(path.join(outDir, file), header + body, 'utf8');
    console.log(`wrote ${file}: lines ${startLine}-${endLine} (${sliceLines.length} lines)`);
}

fs.writeFileSync(
    path.join(outDir, '_login-auth.css'),
    `/* Login/auth rules live in _charts.css (search "Login form" / "CHANGE PASSWORD").
 * Kept there so the built cascade matches the pre-split file.
 * This stub is NOT included in npm run build:owwa-theme.
 */
`,
    'utf8',
);
console.log('wrote _login-auth.css (pointer stub, not in build)');

if (covered !== lines.length) {
    console.error(`ERROR: coverage ${covered} !== ${lines.length}`);
    process.exit(1);
}

console.log(`Split complete. Covered ${covered} lines.`);
