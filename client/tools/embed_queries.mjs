#!/usr/bin/env node
// Offline helper for the model-parity check (NOT run in CI).
//
// Embeds fixtures/parity_strings.json with client/embedder.js using the REAL
// model and prints {dim, queries, vectors} as JSON to stdout, so
// pipeline/tools/model_parity.py can compare it against pipeline/embed.py.
//
// When the self-hosted model exists under client/model/<MODEL_ID>/ it is used
// with remote loading disabled, so the check covers exactly the files the
// storefront serves (see INTEGRATION.md, "Self-hosting the model").
//
// Requires the transformers.js dependency (node_modules is gitignored):
//   npm --prefix client install @xenova/transformers@2.17.2
// then:
//   node client/tools/embed_queries.mjs

import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import * as transformers from '@xenova/transformers';
import { QueryEmbedder, EMBEDDING_DIM, MODEL_ID } from '../embedder.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixture = resolve(here, '../../fixtures/parity_strings.json');
const { queries } = JSON.parse(readFileSync(fixture, 'utf8'));

const modelRoot = resolve(here, '../model');
if (existsSync(resolve(modelRoot, MODEL_ID))) {
  transformers.env.localModelPath = modelRoot + '/';
  transformers.env.allowRemoteModels = false;
  process.stderr.write(`using self-hosted model at ${modelRoot}/${MODEL_ID}\n`);
}

const embedder = new QueryEmbedder({ transformers });
const vectors = await embedder.embedMany(queries);

process.stdout.write(JSON.stringify({ dim: EMBEDDING_DIM, queries, vectors }));
