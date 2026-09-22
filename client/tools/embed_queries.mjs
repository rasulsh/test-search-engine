#!/usr/bin/env node
// Offline helper for the model-parity check (NOT run in CI).
//
// Embeds fixtures/parity_strings.json with client/embedder.js using the REAL
// model and prints {dim, queries, vectors} as JSON to stdout, so
// pipeline/tools/model_parity.py can compare it against pipeline/embed.py.
//
// Requires the transformers.js dependency (node_modules is gitignored):
//   npm --prefix client install @xenova/transformers
// then:
//   node client/tools/embed_queries.mjs

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { QueryEmbedder, EMBEDDING_DIM } from '../embedder.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixture = resolve(here, '../../fixtures/parity_strings.json');
const { queries } = JSON.parse(readFileSync(fixture, 'utf8'));

const embedder = new QueryEmbedder();
const vectors = await embedder.embedMany(queries);

process.stdout.write(JSON.stringify({ dim: EMBEDDING_DIM, queries, vectors }));
