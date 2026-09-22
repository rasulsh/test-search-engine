// Browser query embedder (Tier 2). Runs the SAME model as the offline product
// pipeline (pipeline/embed.py) in-browser via transformers.js (ONNX/WASM), so no
// embedding model ever runs on the server.
//
// Model parity (CLAUDE.md contract #2) — this file MUST stay aligned with
// pipeline/embed.py and the shared config:
//   * same model + revision (intfloat/multilingual-e5-small),
//   * multilingual-e5 is asymmetric: queries are prefixed with "query: "
//     (QUERY_PREFIX) exactly as products are prefixed with "passage: " offline,
//   * mean pooling + L2 normalization, matching sentence-transformers' output.
// Vectors from a different model, prefix, pooling, or normalization are NOT
// comparable and silently wreck ranking.
//
// The constants below are asserted against pipeline/config.py, embed.QUERY_PREFIX,
// and server/config.example.php automatically (pipeline/tests/test_client_parity.py).
// Real numerical parity between this file and embed.py is checked offline by
// pipeline/tools/model_parity.py (it needs the real model + node, so it does not
// run in CI).
//
// Model assets: transformers.js loads onnx/model_quantized.onnx for MODEL_ID, which
// the intfloat repository does not publish, so fetching by id from HuggingFace
// fails. Self-host the Xenova ONNX conversion of the SAME weights under
// client/model/<MODEL_ID>/ and inject a transformers module configured for it
// (INTEGRATION.md, "Self-hosting the model"). The offline parity check verifies
// those files against embed.py.

// Shared model contract. Keep in sync with the pipeline + server config.
export const MODEL_ID = 'intfloat/multilingual-e5-small';
export const MODEL_REVISION = 'main';
export const EMBEDDING_DIM = 384;
export const QUERY_PREFIX = 'query: ';
export const POOLING = 'mean';
export const NORMALIZE = true;

/**
 * Loads the model once and embeds queries into L2-normalized vectors that can be
 * sent as `q_vector` to POST /search.
 */
export class QueryEmbedder {
  /**
   * @param {object} [options]
   * @param {string} [options.modelId]
   * @param {string} [options.revision]
   * @param {number} [options.dim]
   * @param {string} [options.queryPrefix]
   * @param {object} [options.transformers] Injected transformers.js module (tests / offline runner).
   */
  constructor(options = {}) {
    this.modelId = options.modelId ?? MODEL_ID;
    this.revision = options.revision ?? MODEL_REVISION;
    this.dim = options.dim ?? EMBEDDING_DIM;
    this.queryPrefix = options.queryPrefix ?? QUERY_PREFIX;
    this._transformers = options.transformers ?? null;
    this._extractor = null;
  }

  /** Lazily build the feature-extraction pipeline. Safe to call repeatedly. */
  async init() {
    if (this._extractor) {
      return this._extractor;
    }
    const transformers = this._transformers ?? (await import('@xenova/transformers'));
    this._extractor = await transformers.pipeline('feature-extraction', this.modelId, {
      revision: this.revision,
    });
    return this._extractor;
  }

  /**
   * Embed one query string. Prepends QUERY_PREFIX, mean-pools, L2-normalizes.
   * @param {string} query
   * @returns {Promise<number[]>} L2-normalized vector of length `dim`.
   */
  async embed(query) {
    if (typeof query !== 'string') {
      throw new TypeError('query must be a string');
    }
    const extractor = await this.init();
    const output = await extractor(this.queryPrefix + query, {
      pooling: POOLING,
      normalize: NORMALIZE,
    });
    const vector = Array.from(output.data);
    if (vector.length !== this.dim) {
      throw new Error(`embedding dim ${vector.length} != expected ${this.dim}`);
    }
    return vector;
  }

  /**
   * Embed several queries, preserving order.
   * @param {string[]} queries
   * @returns {Promise<number[][]>}
   */
  async embedMany(queries) {
    const out = [];
    for (const query of queries) {
      out.push(await this.embed(query));
    }
    return out;
  }
}

/**
 * Convenience one-shot embed. Prefer a long-lived QueryEmbedder in the storefront
 * so the model loads only once.
 * @param {string} query
 * @param {object} [options]
 * @returns {Promise<number[]>}
 */
export async function embedQuery(query, options = {}) {
  return new QueryEmbedder(options).embed(query);
}
