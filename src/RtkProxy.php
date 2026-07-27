<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Rtk;

use PapiAI\Core\Contracts\LLMTokenOptimisationProxyInterface;
use PapiAI\Core\Contracts\TokenEstimatorInterface;
use PapiAI\Core\HeuristicTokenEstimator;
use PapiAI\Core\OptimisationResult;
use RuntimeException;

/**
 * Token-optimisation proxy backed by the RTK binary (https://github.com/rtk-ai/rtk).
 *
 * RTK is a CLI proxy that compresses verbose developer output (git, grep, test runners, …)
 * before it reaches an LLM. This adapter exposes two entry points:
 *
 *   - optimise():        pipe arbitrary captured text through `rtk pipe` (optionally with a
 *                        named filter such as grep or git-log).
 *   - optimiseCommand(): run a read-only command through RTK's specialised filter and report
 *                        the saving against its raw output.
 *
 * Both are lossy: RTK rewrites the text and its named filters discard detail (the `find` filter,
 * for instance, drops file names). Compress output the model only needs to read. Never pass
 * content the model must reproduce verbatim, such as a source file it is about to edit; RTK
 * either returns it unchanged, for no saving, or mangles it.
 *
 * Token counts come from an injectable {@see TokenEstimatorInterface}, by default the byte-based
 * {@see HeuristicTokenEstimator}, not a real tokenizer.
 */
class RtkProxy implements LLMTokenOptimisationProxyInterface
{
    /**
     * @param string                 $binary    Path to the rtk executable (defaults to "rtk" on PATH)
     * @param TokenEstimatorInterface $estimator Sizes the before/after payloads
     */
    public function __construct(
        private readonly string $binary = 'rtk',
        private readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    /** {@inheritDoc} */
    public function optimise(string $content, array $options = []): OptimisationResult
    {
        $args = ['pipe'];

        if (isset($options['filter'])) {
            $args[] = '--filter';
            $args[] = (string) $options['filter'];
        }

        if (!empty($options['ultraCompact'])) {
            $args[] = '--ultra-compact';
        }

        $optimised = $this->execute(array_merge([$this->binary], $args), $content);
        $strategy = isset($options['filter']) ? 'rtk:pipe:' . $options['filter'] : 'rtk:pipe';

        return new OptimisationResult(
            $optimised,
            $this->estimateTokens($content),
            $this->estimateTokens($optimised),
            $strategy,
        );
    }

    /**
     * {@inheritDoc}
     *
     * Measuring the saving costs a second execution of the command, unfiltered. Pass
     * `measure: false` to run it once, through RTK only: `tokensBefore` is then null and no
     * saving is reported.
     */
    public function optimiseCommand(string $command, array $options = []): OptimisationResult
    {
        $tokensBefore = ($options['measure'] ?? true)
            ? $this->estimateTokens($this->execute(['sh', '-c', $command]))
            : null;

        $optimised = $this->execute(['sh', '-c', $this->binary . ' ' . $this->withUltraCompact($command, $options)]);

        return new OptimisationResult(
            $optimised,
            $tokensBefore,
            $this->estimateTokens($optimised),
            'rtk:command',
        );
    }

    /** {@inheritDoc} */
    public function estimateTokens(string $content): int
    {
        return $this->estimator->estimateTokens($content);
    }

    /**
     * Run a process, optionally feeding it stdin, and return its stdout.
     *
     * Isolated so tests can stub process execution. Uses an argv array (no shell) except where
     * the caller explicitly passes an `sh -c` invocation.
     *
     * @param array<int, string> $argv  The command and its arguments
     * @param string|null        $stdin Data to write to the process stdin, if any
     *
     * @return string The process stdout (empty string on no output)
     *
     * @throws RuntimeException When the process cannot be started
     */
    protected function execute(array $argv, ?string $stdin = null): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($argv, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to start "%s".', $argv[0] ?? 'process'));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === false ? '' : $stdout;
    }

    /**
     * Insert RTK's ultra-compact flag where RTK expects it: after the filter name, before the
     * command's own arguments (`rtk git --ultra-compact status`).
     *
     * @param string              $command The command line being proxied
     * @param array<string, mixed> $options The caller's options
     *
     * @return string The command line, with the flag inserted when requested
     */
    private function withUltraCompact(string $command, array $options): string
    {
        if (empty($options['ultraCompact'])) {
            return $command;
        }

        $parts = explode(' ', $command, 2);

        return rtrim($parts[0] . ' --ultra-compact ' . ($parts[1] ?? ''));
    }
}
