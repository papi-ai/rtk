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

use PapiAI\Core\Contracts\LLMTokenOptimisationProxyInterface;
use PapiAI\Core\OptimisationResult;
use PapiAI\Rtk\RtkProxy;

/**
 * Test subclass that stubs process execution so no real `rtk` binary runs.
 */
class TestableRtkProxy extends RtkProxy
{
    /** @var array<int, array{argv: array<int, string>, stdin: string|null}> */
    public array $calls = [];

    protected function execute(array $argv, ?string $stdin = null): string
    {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdin];

        // `rtk pipe` (argv contains 'pipe')
        if (in_array('pipe', $argv, true)) {
            return 'PIPED';
        }

        // filtered command run: sh -c "rtk <command>"
        if (($argv[0] ?? '') === 'sh' && str_starts_with($argv[2] ?? '', 'rtk ')) {
            return 'SHORT';
        }

        // raw command run: sh -c "<command>"
        return 'a much longer raw command output that costs many more tokens';
    }
}

describe('RtkProxy', function () {
    beforeEach(function () {
        $this->proxy = new TestableRtkProxy();
    });

    it('implements the optimisation proxy contract', function () {
        expect($this->proxy)->toBeInstanceOf(LLMTokenOptimisationProxyInterface::class);
    });

    describe('estimateTokens', function () {
        it('estimates ~4 bytes per token', function () {
            expect($this->proxy->estimateTokens('abcd'))->toBe(1);
            expect($this->proxy->estimateTokens('abcde'))->toBe(2);
            expect($this->proxy->estimateTokens(''))->toBe(0);
        });
    });

    describe('optimise', function () {
        it('pipes content through rtk pipe and returns a result', function () {
            $result = $this->proxy->optimise('some noisy   output');

            expect($result)->toBeInstanceOf(OptimisationResult::class);
            expect($result->optimised)->toBe('PIPED');
            expect($result->strategy)->toBe('rtk:pipe');

            $call = $this->proxy->calls[0];
            expect($call['argv'])->toBe(['rtk', 'pipe']);
            expect($call['stdin'])->toBe('some noisy   output');
        });

        it('passes a named filter and ultra-compact flag', function () {
            $result = $this->proxy->optimise('x', ['filter' => 'grep', 'ultraCompact' => true]);

            expect($this->proxy->calls[0]['argv'])->toBe(['rtk', 'pipe', '--filter', 'grep', '--ultra-compact']);
            expect($result->strategy)->toBe('rtk:pipe:grep');
        });

        it('reports token savings when the output is smaller', function () {
            // 'PIPED' (5 bytes -> 2 tokens) vs a longer input
            $result = $this->proxy->optimise(str_repeat('x', 400));

            expect($result->tokensBefore)->toBe(100);
            expect($result->tokensAfter)->toBe(2);
            expect($result->savingsPercent())->toBeGreaterThan(90.0);
        });

        it('honours a custom binary path', function () {
            $proxy = new class ('/opt/rtk') extends RtkProxy {
                public array $calls = [];

                protected function execute(array $argv, ?string $stdin = null): string
                {
                    $this->calls[] = ['argv' => $argv, 'stdin' => $stdin];

                    return 'PIPED';
                }
            };

            $proxy->optimise('x');

            expect($proxy->calls[0]['argv'][0])->toBe('/opt/rtk');
        });
    });

    describe('execute (real process)', function () {
        it('captures stdout from a real process', function () {
            $proxy = new class () extends RtkProxy {
                public function run(array $argv, ?string $stdin = null): string
                {
                    return $this->execute($argv, $stdin);
                }
            };

            expect($proxy->run(['sh', '-c', 'printf hello']))->toBe('hello');
        });

        it('feeds stdin to a real process', function () {
            $proxy = new class () extends RtkProxy {
                public function run(array $argv, ?string $stdin = null): string
                {
                    return $this->execute($argv, $stdin);
                }
            };

            expect($proxy->run(['cat'], 'piped-in'))->toBe('piped-in');
        });
    });

    describe('optimiseCommand', function () {
        it('runs the raw command and the rtk-filtered command, measuring the saving', function () {
            $result = $this->proxy->optimiseCommand('git status');

            expect($result->optimised)->toBe('SHORT');
            expect($result->strategy)->toBe('rtk:command');
            expect($result->tokensBefore)->toBeGreaterThan($result->tokensAfter);
            expect($result->savingsPercent())->toBeGreaterThan(0.0);

            // two executions: raw, then rtk-filtered
            expect($this->proxy->calls)->toHaveCount(2);
            expect($this->proxy->calls[0]['argv'])->toBe(['sh', '-c', 'git status']);
            expect($this->proxy->calls[1]['argv'])->toBe(['sh', '-c', 'rtk git status']);
        });
    });
});
