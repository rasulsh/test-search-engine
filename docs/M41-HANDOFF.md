# M41 hand-off (work in progress, delete before merging)

The session that started M41 lost its shell (the root symlinks /bin /lib /lib64 /sbin disappeared during a PHPUnit run, so
no executable could start). Everything below was done in that session's working tree but could not be pushed with git;
only `server/src/AliasStore.php` and `server/src/AliasStoreException.php` are on this branch. Re-apply the rest from this note.

**First, find out what removed the root symlinks.** The last command was `phpunit --filter "SearchController|Speller|Synonyms|Keyword|Reload|Eval|Endpoint|Cache"`.
Something in that selection (a test tearDown, `Reload::removeDir`, a temp-dir helper given an empty or root path) may delete
symlinks at `/`. Run those classes one by one in a disposable container before trusting them. Do not run the suite on a machine you care about.

## Decisions (approved by the owner)

- `server/storage/aliases.json` is the single source of the owner's aliases; no aliases in the build or the bundle; `/reload` ignores a bundled `aliases.json`.
- Storage is seeded from `data/aliases.json` when missing (first panel visit); until then the search reads the live bundle's file (`AliasStore::runtimePath`).
- `pipeline/aliases.json` is deprecated: a seed for new installs (copy it once to `server/storage/aliases.json`, or paste it in the panel's advanced editor); a "download aliases.json" button stays as a backup.
- The Speller overlay is built from the live alias file only (`Synonyms::aliasTokens()` -> `Speller::withExtraWords`).
- The search tester ships with the alias "Test" button (no log row, no result cache).
- Alias product counts are computed on demand (per term or group), never for the whole list on page load.
- `config-check --verbose` lists every key: value (secrets masked) and source (config.php or code default), not only the defaults.

## Applied in the lost working tree (re-apply)

Pipeline (all tests passed there: pytest 324 passed, ruff clean):
- `pipeline/build.py`: stop reading aliases, stop writing `aliases.json`, `build_spellcheck(server_rows)` without aliases, drop `--aliases`, docstring says aliases live on the server.
- `pipeline/release.py`: drop `--aliases` and the alias validation; docstring updated.
- `pipeline/keyword.py`: `build_spellcheck` loses its `aliases` parameter and loop; keep `ALIAS_TOKEN_COUNT = 5` (comment: the server applies it now) and `load_aliases` (gen_aliases.py uses it).
- `pipeline/config.py`: comment on `build.aliases_file`: deprecated, only gen_aliases --merge target / seed.
- tests: `test_keyword.py` (spellcheck has no `aliases` parameter, alias words absent), `test_build.py` (no `aliases.json` in the bundle, `--aliases` unrecognized, aliases never reach spellcheck), `test_release.py` (BUNDLE_FILES without aliases.json, release ships none and rejects `--aliases`).

Server:
- `Config.php`: `paths.storage` = `$root/storage`; `admin.alias_max_term_chars` 64, `admin.alias_warn_matches` 500, `admin.alias_backups` 20.
- `Synonyms.php`: `fromDirectory($dataDir, $max, ?string $aliasesPath = null)` (default stays `data/aliases.json`); constructor takes `$aliasTokens`; new `aliasTokens()` (tokens of the merged owner alias groups).
- `Reload.php`: remove the `invalid_aliases` check from `assertGroupFiles()` (bundled aliases are ignored); docblocks updated. `AdminStrings.php` loses the `invalid_aliases` reason; `AdminOpsTest` reason list too. `ReloadTest` data provider and `testReloadServesTheNewAliasFile` must change: a malformed or changed bundled `aliases.json` no longer matters and `/reload` never touches `storage/aliases.json`.
- `Speller.php`: `const ALIAS_TOKEN_COUNT = 5`; `withExtraWords(array $tokens, int $count, string $version): self` (clone; for each token of 2+ chars whose count is below `$count`: set it, update the entry in `byLength[len]` or append `[token, encoded|null, count]` using `codes`; cached per base speller and version in a `WeakMap`).
- `SearchController.php`: `$logger` nullable (`writeLog` returns when null); `fromConfig(..., bool $record = true)` (false: no Logger, no result cache); new public statics `synonymsFromConfig($config)` (uses `AliasStore::fromConfig($config)->runtimePath()`) and `keywordFromConfig($pdo, $config, ?Synonyms)` (the Keyword construction moved out of `fromConfig`); the speller factory chains `->withExtraWords($synonyms->aliasTokens(), Speller::ALIAS_TOKEN_COUNT, $synonyms->version())->withBlocked($blocked)`.
- `AliasStore` / `AliasStoreException` (this branch): storage file, flock + temp + atomic rename, stale-version rejection, backups (newest 20, `backups/aliases-YYYYmmdd-HHMMSS-uuuuuu.json`), restore, seed, `storage/.htaccess` deny-all created on first write.

## Still to do

- Ship `server/storage/.htaccess` (deny all) in `release.zip` like `data/.htaccess`; `.gitignore` `/server/storage/*` except `.htaccess`; `release.py` + its test; HtaccessLayoutTest.
- `AliasValidator` (server side; client side mirrors the cheap rules). Errors (block save): not a list of lists of strings, empty term (also a term that normalizes to nothing), a group with fewer than 2 distinct normalized terms, duplicate terms in a group after normalization, a term longer than `admin.alias_max_term_chars`. Warnings (need an explicit confirm, only for terms of groups that changed): the term is in another group (show the `Synonyms::mergeOverlapping` result), shorter than 3 characters, matches more than `admin.alias_warn_matches` products (needs `AliasCounts`: a literal `Keyword` built with `keywordFromConfig(..., null)`, count hits with coverage 1, capped), in the did-you-mean blocklist (`search.suggest_blocklist`).
- Panel pages in `admin.php`: `aliases` (searchable group list, add / edit / delete groups and terms, normalized form per term, on-demand counts, effective merged group, "Test" showing the top 10 titles, advanced raw-JSON editor with the same validator, download button, save with version hash and warning confirmation, audit rows `alias_save` / `alias_restore`), `aliases history` (backups, line diff to the current file, one-click restore), `synonyms` (read-only `synonyms.json`, "which groups contain term X" with alias groups, synonym groups and the effective merged group, "Copy to aliases" prefilling the editor, help box explaining that hand edits are lost on the next build), `tester` (search without a log row: tier, did-you-mean, top 10 titles with match type). Nav entries, `AdminStrings` texts and help boxes (what / how / effect / when / risk), docs (ADMIN.md, ALIASES.md, DEPLOY.md upgrade notes, CONFIGURATION.md, CHANGELOG.md M41 config section, PIPELINE.md, SEARCH-BEHAVIOR.md, INTEGRATION.md `invalid_aliases`), CLAUDE.md (bundle contents without aliases.json, structure, M41 entry).
- `tools/config-check.php --verbose`: print every key as `INFO key = value [config.php|code default]`, secrets as set / empty, unknown keys hidden (reuse `ConfigReport::rows`); update `ConfigCheckToolTest`.
- Propose (do not build) an exclusion list for bad synonym groups in the PR description.
- Tests for everything above (validator case by case, atomic save, stale rejection, backup restore, seed, runtime reads storage, overlay deleted-term behaviour), browser check in both themes and RTL with screenshots, then the PR report in the CLAUDE.md section 12 format.
