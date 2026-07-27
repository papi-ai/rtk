# papi-ai/rtk

RTK token-optimisation proxy adapter for [PapiAI](https://papi-ai.org).

Wraps the [RTK](https://github.com/rtk-ai/rtk) CLI (a proxy that compresses verbose developer
output before it reaches an LLM) behind PapiAI's `LLMTokenOptimisationProxyInterface`, so agents
and tools can shrink command output, often by 60-90%, before it enters the context window.

## Install

```bash
composer require papi-ai/rtk
```

Requires `papi-ai/papi-core` `^0.11` and the [`rtk`](https://github.com/rtk-ai/rtk) binary on
your `PATH` (`brew install rtk`, or see the RTK install docs).

## Usage

```php
use PapiAI\Rtk\RtkProxy;

$rtk = new RtkProxy();               // or new RtkProxy('/custom/path/to/rtk')

// Compress captured text (e.g. a tool's stdout) through a named RTK filter
$result = $rtk->optimise($grepOutput, ['filter' => 'grep']);
echo $result->optimised;             // compact text
echo $result->savingsPercent();      // e.g. 62.5

// Or run a read-only command through RTK's specialised filter and measure the saving
$result = $rtk->optimiseCommand('git status');
echo $result->tokensBefore, ' -> ', $result->tokensAfter;

// Measuring runs the command twice (raw, then via RTK). Skip the baseline when you only
// want the output: one execution, `tokensBefore` and `savingsPercent()` are then null.
$result = $rtk->optimiseCommand('git status', ['measure' => false]);
```

Use it inside a tool so a command's output is compressed before it re-enters the agent's context:

```php
use PapiAI\Core\Tool;

$tool = Tool::make(
    name: 'git_status',
    description: 'Show the working tree status, token-optimised',
    parameters: [],
    handler: fn () => (new RtkProxy())->optimiseCommand('git status')->optimised,
);
```

## What to send through it (and what never to)

Optimisation is lossy by design. RTK rewrites the text, and its named filters drop detail they
consider incidental (the `find` filter, for example, discards file names).

**Only compress text the model needs to read.** Command output, logs, test runs, diffs: that is
where the savings are, and losing formatting costs nothing.

**Never compress text the model must reproduce verbatim.** Source files it is about to edit,
whole-file payloads, anything round-tripped back to disk. Measured on a 15.7KB PHP file: an
unfiltered `rtk pipe` returned it byte-identical (safe, but zero saving), while the named filters
that do save tokens mangled it. There is nothing to win and a file to lose.

Text that is already summarised at the source has nothing left to squeeze either. If your tool
already returns a structured report rather than raw runner output, you solved the same problem
upstream.

## API

`RtkProxy implements LLMTokenOptimisationProxyInterface` (which extends `TokenEstimatorInterface`):

| Method | Purpose |
|---|---|
| `optimise(string $content, array $options = [])` | Pipe text through `rtk pipe` (`filter`, `ultraCompact` options) |
| `optimiseCommand(string $command, array $options = [])` | Run a read-only command through RTK (`measure`, `ultraCompact` options) |
| `estimateTokens(string $content)` | Cheap byte-based estimate, delegated to an injectable estimator |

Both optimise methods return an `OptimisationResult` (`optimised`, `tokensBefore`, `tokensAfter`,
`isMeasured()`, `tokensSaved()`, `savingsPercent()`, `strategy`). Token counts are estimates, not a
real tokenizer: by default `PapiAI\Core\HeuristicTokenEstimator` (bytes divided by 4, so multibyte
text estimates high). Inject any `TokenEstimatorInterface` to change that:

```php
$rtk = new RtkProxy('rtk', new MyTokenizerBackedEstimator());
```

> `optimiseCommand()` executes the command (raw, then via RTK) to measure the saving, so pass only
> side-effect-free commands (git status, grep, ls, test runners). `['measure' => false]` runs it
> once, through RTK only.

## Where it fits

Nothing in PapiAI wires an optimiser in for you, by design. Agent middleware only sees the request
prompt (tool results are resolved inside the run loop), so a middleware could never reach the text
RTK is good at. The integration point is your own tool handler, as in the example above: run the
command, compress its output, return the compressed text.

## License

MIT © Marcello Duarte
