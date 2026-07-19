# RTK adapter

`papi-ai/rtk` implements `LLMTokenOptimisationProxyInterface` by driving the
[RTK](https://github.com/rtk-ai/rtk) binary — a CLI proxy that compresses verbose developer
output before it reaches an LLM (`rtk gain` routinely reports 60–90% savings across git, grep,
test, and build output).

## Where RTK helps

The biggest, noisiest contributor to an agent's context is usually tool output: a full `git diff`,
a `grep` across a repo, a failing test run. RTK strips padding, deduplicates, truncates, and
summarises that output while preserving the signal. Feeding the *optimised* text back into the
model costs a fraction of the tokens.

## Two entry points

### `optimise(string $content, array $options = [])`

Pipes text you already captured through `rtk pipe`. Pass a named `filter` matching the content so
RTK knows how to compress it:

```php
$result = $rtk->optimise($output, ['filter' => 'git-log']);
$result = $rtk->optimise($output, ['filter' => 'grep', 'ultraCompact' => true]);
```

Without a filter, `rtk pipe` passes the text through largely unchanged (reported honestly as a
near-zero saving).

### `optimiseCommand(string $command, array $options = [])`

Runs a command through RTK's specialised per-command filter, which compresses better than the
generic pipe. To measure the saving it runs the command **twice** — once raw, once via `rtk` — so
only pass side-effect-free commands:

```php
$result = $rtk->optimiseCommand('git status');
$result = $rtk->optimiseCommand('grep -rn TODO src');
```

## OptimisationResult

Every call returns an immutable `OptimisationResult`:

| Member | Meaning |
|---|---|
| `optimised` | the compressed text |
| `tokensBefore` / `tokensAfter` | estimated token counts (≈ 4 bytes/token) |
| `tokensSaved()` | `max(0, before - after)` |
| `savingsPercent()` | saving as a percentage |
| `strategy` | `rtk:pipe`, `rtk:pipe:<filter>`, or `rtk:command` |

## Requirements

The `rtk` binary must be on `PATH` at runtime (`brew install rtk`). It is **not** a Composer
dependency — the adapter shells out to whatever `rtk` (or the path you pass to `new RtkProxy($path)`)
resolves to.
