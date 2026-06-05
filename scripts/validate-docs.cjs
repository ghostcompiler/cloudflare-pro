const fs = require('fs');
const path = require('path');

const root = process.cwd();
const requiredFiles = [
  'README.md',
  'docs/index.html',
  'docs/INSTALL.md',
  'docs/ARCHITECTURE.md',
  'docs/API.md',
  'docs/SECURITY.md',
  'packaging/manifest.json',
];

for (const file of requiredFiles) {
  const fullPath = path.join(root, file);
  if (!fs.existsSync(fullPath)) {
    throw new Error(`Missing required documentation file: ${file}`);
  }
}

JSON.parse(fs.readFileSync(path.join(root, 'packaging/manifest.json'), 'utf8'));

const docsIndex = fs.readFileSync(path.join(root, 'docs/index.html'), 'utf8');
const localRefs = [...docsIndex.matchAll(/(?:href|src)="([^"]+)"/g)]
  .map(match => match[1])
  .filter(ref => !ref.startsWith('http') && !ref.startsWith('#') && !ref.startsWith('mailto:'));

for (const ref of localRefs) {
  const normalized = ref.split('#')[0].split('?')[0];
  if (!normalized) {
    continue;
  }

  const target = path.join(root, 'docs', normalized);
  if (!fs.existsSync(target)) {
    throw new Error(`Broken docs reference in docs/index.html: ${ref}`);
  }
}

console.log('Documentation validation passed.');
