# papi-ai/rtk

RTK token-optimisation proxy adapter for [PapiAI](https://papi-ai.org).

Wraps the [RTK](https://github.com/rtk-ai/rtk) CLI (a proxy that compresses verbose developer
output before it reaches an LLM) behind PapiAI's `LLMTokenOptimisationProxyInterface`, so agents
and tools can shrink tool/command output — often by 60–90% — before it enters the context window.

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

## API

`RtkProxy implements LLMTokenOptimisationProxyInterface`:

| Method | Purpose |
|---|---|
| `optimise(string $content, array $options = [])` | Pipe text through `rtk pipe` (`filter`, `ultraCompact` options) |
| `optimiseCommand(string $command, array $options = [])` | Run a read-only command through RTK and measure the saving |
| `estimateTokens(string $content)` | Cheap ~4-bytes-per-token estimate |

All return an `OptimisationResult` (`optimised`, `tokensBefore`, `tokensAfter`, `tokensSaved()`,
`savingsPercent()`, `strategy`). Token counts are estimates, not a real tokenizer.

> `optimiseCommand()` executes the command (raw, then via RTK) to measure the saving — only pass
> side-effect-free commands (git status, grep, ls, test runners).

## License

MIT © Marcello Duarte
