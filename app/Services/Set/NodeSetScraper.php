<?php

namespace App\Services\Set;

use App\Contracts\SetScraper;
use App\Enums\SetSession;
use App\Exceptions\SetScraperException;
use App\Support\Set\SetScrapeResult;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the Playwright scraper (scripts/set-scraper/set-capture.mjs) via
 * Symfony\Process and decodes its single JSON line. The only place the browser
 * transport is invoked.
 */
class NodeSetScraper implements SetScraper
{
    /**
     * @param  array<string, mixed>  $config  the `set` config array
     */
    public function __construct(private readonly array $config) {}

    public function capture(SetSession $session): SetScrapeResult
    {
        $process = new Process($this->buildCommand($session));
        $process->setTimeout((float) ($this->config['process_timeout'] ?? 180));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new SetScraperException("SET scraper timed out for {$session->value}.", 0, $e);
        } catch (ProcessRuntimeException $e) {
            throw new SetScraperException("SET scraper could not start: {$e->getMessage()}", 0, $e);
        }

        $json = $this->decode($process->getOutput(), $session, $process->getErrorOutput());

        if (($json['ok'] ?? false) !== true) {
            $error = $json['error'] ?? 'unknown error';
            throw new SetScraperException("SET scraper failed for {$session->value}: {$error}");
        }

        return SetScrapeResult::fromNode($json);
    }

    /**
     * @return string[]
     */
    private function buildCommand(SetSession $session): array
    {
        $command = [
            (string) ($this->config['node_binary'] ?? 'node'),
            (string) ($this->config['script_path'] ?? ''),
            '--mode='.$session->scraperMode(),
            '--index-field='.$session->indexField(),
            '--symbol='.(string) ($this->config['symbol'] ?? 'SET'),
            '--warmup-url='.(string) ($this->config['warmup_url'] ?? ''),
            '--api-url='.(string) ($this->config['api_url'] ?? ''),
        ];

        if ($session->scraperMode() === 'poll') {
            $poll = $this->config['poll'] ?? [];
            $command[] = '--poll-interval='.(int) ($poll['interval'] ?? 12);
            $command[] = '--max-duration='.(int) ($poll['max_duration'] ?? 90);
            $command[] = '--stable-streak='.(int) ($poll['stable_streak'] ?? 2);
        } else {
            $retry = $this->config['retry'] ?? [];
            $command[] = '--poll-interval='.(int) ($retry['interval'] ?? 10);
            $command[] = '--max-attempts='.(int) ($retry['max_attempts'] ?? 5);
        }

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $output, SetSession $session, string $stderr): array
    {
        // The scraper emits exactly one JSON object; take the last non-empty line.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $last = end($lines);

        if ($last === false || $last === '') {
            if ($stderr !== '') {
                Log::warning('set scraper stderr', ['session' => $session->value, 'stderr' => $stderr]);
            }
            throw new SetScraperException("SET scraper produced no output for {$session->value}.");
        }

        try {
            $decoded = json_decode($last, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SetScraperException("SET scraper returned invalid JSON for {$session->value}: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new SetScraperException("SET scraper returned a non-object payload for {$session->value}.");
        }

        return $decoded;
    }
}
