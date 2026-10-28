<?php

declare(strict_types=1);

namespace UbModePhp;

final class Progress
{
    private bool $terminal;
    private TerminalStyle $style;
    private string $stage = '';
    private int $width = 0;
    private int $bucket = -1;
    private float $lastUpdate = 0;
    private bool $lineOpen = false;

    public function __construct(private bool $enabled, private int $stages = 4)
    {
        $this->terminal = function_exists('stream_isatty') && stream_isatty(STDERR);
        $this->style = new TerminalStyle(STDERR);
    }

    public function stage(int $number, string $label, int $total = 1): void
    {
        $this->closeLine();
        $this->stage = $number . '/' . $this->stages . ' ' . $label;
        $this->bucket = -1;
        $this->lastUpdate = 0;
        $this->width = 0;
        $this->update(0, $total);
    }

    public function update(int $completed, int $total, string $detail = ''): void
    {
        if (!$this->enabled) {
            return;
        }
        $percent = $total === 0 ? 100 : (int) floor($completed * 100 / $total);
        $bucket = intdiv($percent, 10);
        $now = microtime(true);
        $boundary = $completed === 0 || $completed === $total;
        if (!$boundary && ($this->terminal ? $now - $this->lastUpdate < 0.1 : $bucket <= $this->bucket)) {
            return;
        }
        $this->bucket = $bucket;
        $this->lastUpdate = $now;
        $detail = strlen($detail) > 60 ? '...' . substr($detail, -57) : $detail;
        $filled = (int) floor($percent * 24 / 100);
        $bar = $this->terminal ? '[' . str_repeat('#', $filled) . str_repeat('-', 24 - $filled) . '] ' : '';
        $line = sprintf('%s %s%3d%% %d/%d %s', $this->stage, $bar, $percent, $completed, $total, $detail);
        if ($this->terminal) {
            fwrite(STDERR, "\r" . $this->style->paint(str_pad($line, $this->width), $completed === $total ? '32' : '36'));
            $this->width = max($this->width, strlen($line));
            $this->lineOpen = true;
            if ($completed === $total) {
                $this->closeLine();
            }
        } else {
            fwrite(STDERR, rtrim($line) . "\n");
        }
    }

    public function skip(int $number, string $label, string $reason): void
    {
        $this->closeLine();
        $this->stage = $number . '/' . $this->stages . ' ' . $label;
        if ($this->enabled) {
            fwrite(STDERR, $this->style->paint($this->stage . ': skipped (' . $reason . ')', '2') . "\n");
        }
    }

    public function activity(int $number, string $label): void
    {
        $this->closeLine();
        $this->stage = $number . '/' . $this->stages . ' ' . $label;
        if ($this->enabled) {
            fwrite(STDERR, $this->style->paint($this->stage, '36') . "\n");
        }
    }

    public function complete(): void
    {
        $this->closeLine();
        if ($this->enabled) {
            fwrite(STDERR, $this->style->paint("Completed: analysis and configured reports finished.", '32') . "\n");
        }
    }

    public function fail(): void
    {
        $this->closeLine();
        if ($this->enabled && $this->stage !== '') {
            fwrite(STDERR, $this->style->paint('Stopped during ' . $this->stage . '.', '31') . "\n");
        }
    }

    private function closeLine(): void
    {
        if ($this->lineOpen) {
            fwrite(STDERR, "\n");
            $this->lineOpen = false;
        }
    }
}
