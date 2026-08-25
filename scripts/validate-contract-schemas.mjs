import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const root = fileURLToPath(new URL('..', import.meta.url));
const contractsRoot = join(root, 'config', 'contracts');

function filesBelow(directory) {
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...filesBelow(path));
    } else if (entry.isFile() && extname(entry.name) === '.json') {
      files.push(path);
    }
  }
  return files.sort();
}

function load(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

function invariantAnnotations(value, pointer = '#', found = []) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => invariantAnnotations(item, `${pointer}/${index}`, found));
    return found;
  }
  if (value === null || typeof value !== 'object') {
    return found;
  }
  for (const [key, nested] of Object.entries(value)) {
    if (key === 'x-invariants') {
      found.push({ pointer: `${pointer}/x-invariants`, values: nested });
    }
    invariantAnnotations(nested, `${pointer}/${key.replaceAll('~', '~0').replaceAll('/', '~1')}`, found);
  }
  return found;
}

function assertValid(validate, value, label) {
  if (validate(value)) {
    return;
  }
  const details = (validate.errors ?? [])
    .map((error) => `${error.instancePath || '/'} ${error.message ?? 'is invalid'}`)
    .join('; ');
  throw new Error(`${label} failed schema validation: ${details}`);
}

const documents = filesBelow(contractsRoot).map((path) => ({
  path,
  relativePath: relative(root, path),
  document: load(path),
}));
const schemas = documents.filter(({ document }) => document.$schema !== undefined || document.$id !== undefined);

assert(schemas.length > 0, 'No JSON Schema documents were found.');
const schemaIds = new Set();
for (const { relativePath, document } of schemas) {
  assert.equal(document.$schema, 'https://json-schema.org/draft/2020-12/schema', `${relativePath} is not Draft 2020-12`);
  assert.equal(typeof document.$id, 'string', `${relativePath} has no $id`);
  assert(document.$id.startsWith('https://veyra.invalid/contracts/'), `${relativePath} has an unexpected $id namespace`);
  assert(!schemaIds.has(document.$id), `Duplicate schema $id: ${document.$id}`);
  schemaIds.add(document.$id);
}

const ajv = new Ajv2020({
  allErrors: true,
  allowUnionTypes: true,
  strict: true,
  validateFormats: true,
});
addFormats(ajv);
ajv.addKeyword({
  keyword: 'x-invariants',
  schemaType: 'array',
  metaSchema: {
    type: 'array',
    minItems: 1,
    uniqueItems: true,
    items: { type: 'string', minLength: 1, maxLength: 2000 },
  },
  valid: true,
});

for (const { document } of schemas) {
  ajv.addSchema(document);
}
for (const { relativePath, document } of schemas) {
  assert(ajv.getSchema(document.$id), `${relativePath} did not compile or resolve its references`);
}

const annotations = schemas.flatMap(({ relativePath, document }) =>
  invariantAnnotations(document).map((entry) => ({ relativePath, ...entry })),
);
for (const annotation of annotations) {
  assert(Array.isArray(annotation.values), `${annotation.relativePath}${annotation.pointer} is not an array`);
  assert(annotation.values.length > 0, `${annotation.relativePath}${annotation.pointer} is empty`);
  assert.equal(new Set(annotation.values).size, annotation.values.length, `${annotation.relativePath}${annotation.pointer} contains duplicates`);
  for (const invariant of annotation.values) {
    assert.equal(typeof invariant, 'string', `${annotation.relativePath}${annotation.pointer} contains a non-string invariant`);
    assert(invariant.trim().length > 0, `${annotation.relativePath}${annotation.pointer} contains an empty invariant`);
  }
}

const featureValidator = ajv.getSchema('https://veyra.invalid/contracts/feature-contract.schema.json');
const toolValidator = ajv.getSchema('https://veyra.invalid/contracts/universal-tool-contract.schema.json');
assert(featureValidator, 'Feature contract validator is unavailable.');
assert(toolValidator, 'Universal tool contract validator is unavailable.');

const featureRegistry = load(join(contractsRoot, 'feature-registry.json'));
const toolCatalog = load(join(contractsRoot, 'logical-tool-catalog.json'));
for (const [index, feature] of featureRegistry.entries.entries()) {
  assertValid(featureValidator, feature, `feature-registry.entries[${index}]`);
}
for (const [index, tool] of toolCatalog.tools.entries()) {
  assertValid(toolValidator, tool, `logical-tool-catalog.tools[${index}]`);
}

const invalidFeature = structuredClone(featureRegistry.entries[0]);
invalidFeature.uncontracted_field = true;
assert.equal(featureValidator(invalidFeature), false, 'Feature contract accepted an unknown property.');
const invalidTool = structuredClone(toolCatalog.tools[0]);
delete invalidTool.name;
assert.equal(toolValidator(invalidTool), false, 'Tool contract accepted a missing required name.');
assert.throws(
  () => ajv.compile({
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    $id: 'https://veyra.invalid/contracts/tests/unresolved.schema.json',
    $ref: 'missing.schema.json',
  }),
  /resolve reference|MissingRefError|can't resolve/i,
  'The validator did not fail closed on an unresolved cross-file reference.',
);

console.log(`PASS Draft 2020-12 schema compilation: ${schemas.length} schemas`);
console.log(`PASS cross-file references and x-invariants annotations: ${annotations.length} annotations`);
console.log(`PASS registry instances: ${featureRegistry.entries.length} features, ${toolCatalog.tools.length} logical tools`);
