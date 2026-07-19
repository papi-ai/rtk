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
 * Token counts are cheap byte-based estimates (~4 bytes per token), not a real tokenizer.
 */
class RtkProxy implements LLMTokenOptimisationProxyInterface
{
    /**
     * @param string $binary Path to the rtk executable (defaults to "rtk" on PATH)
     */
    public function __construct(
        private readonly string $binary = 'rtk',
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

    /** {@inheritDoc} */
    public function optimiseCommand(string $command, array $options = []): OptimisationResult
    {
        $raw = $this->execute(['sh', '-c', $command]);
        $optimised = $this->execute(['sh', '-c', $this->binary . ' ' . $command]);

        return new OptimisationResult(
            $optimised,
            $this->estimateTokens($raw),
            $this->estimateTokens($optimised),
            'rtk:command',
        );
    }

    /** {@inheritDoc} */
    public function estimateTokens(string $content): int
    {
        return (int) ceil(strlen($content) / 4);
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
}
