<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use ColinODell\Json5\Json5Decoder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UbModePhp\Config;
use UbModePhp\Reports;
use UbModePhp\SourceFiles;

/**
 * Hashes everything a mutation measurement depends on: selected source, tests and the
 * Composer/Infection/PHPUnit configuration, minus declared generated outputs.
 */
final class InputFingerprint
{
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git', '.metrics', '.phpunit.cache'];

    /** @var list<string> files that stay protected even if an exclusion overlaps them */
    private array $mandatory = [];

    /** @var list<string> files or directories to hash */
    private array $roots = [];

    /** @var list<string> generated outputs and explicit exclusions */
    private array $exclude = [];

    private function __construct(private readonly string $root, private readonly array $config)
    {
    }

    /** @return array{source: array<string, string>, inputs: array<string, string>, configuration: string} */
    public static function compute(string $root, array $config): array
    {
        $root = realpath($root) ?: throw new RuntimeException('project root does not exist');
        $sourceFiles = SourceFiles::find($root, $config, false);
        $fingerprint = new self($root, $config);
        $fingerprint->collect();
        $inputs = $fingerprint->hash($sourceFiles);
        $source = array_intersect_key($inputs, array_fill_keys($sourceFiles, true));
        return ['source' => $source, 'inputs' => $inputs, 'configuration' => self::configurationHash($config)];
    }

    private function collect(): void
    {
        $this->exclude = [Config::enginePath($this->root, $this->config['reports']['directory'], 'reports.directory', false)];
        foreach ($this->config['mutator']['input_exclude'] as $path) {
            $this->exclude[] = Config::enginePath($this->root, $path, 'mutator.input_exclude', false);
        }
        foreach (['composer.json', 'composer.lock', $this->config['mutator']['configuration']] as $path) {
            if (is_file($this->root . '/' . $path)) {
                $this->mandatory[] = Config::enginePath($this->root, $path, 'mutation input');
            }
        }
        $settings = $this->infectionSettings();
        $configDir = Config::enginePath($this->root, $settings['phpUnit']['configDir'] ?? '.', 'phpUnit.configDir');
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            if (is_file($configDir . '/' . $name)) {
                $this->phpunitInputs($configDir, $name);
            }
        }
        foreach (['tests', 'test', 'spec', 'fixtures'] as $directory) {
            if (is_dir($this->root . '/' . $directory)) {
                $this->roots[] = Config::enginePath($this->root, $directory, 'test input directory');
            }
        }
        foreach ($this->config['mutator']['input_paths'] as $path) {
            $this->roots[] = Config::enginePath($this->root, $path, 'mutator.input_paths');
        }
    }

    /** @return array<string, mixed> decoded Infection configuration, empty when absent */
    private function infectionSettings(): array
    {
        $file = $this->root . '/' . $this->config['mutator']['configuration'];
        if (!is_file($file)) {
            return [];
        }
        $settings = Json5Decoder::decode(Reports::read($file), true);
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Infection configuration must be an object');
        }
        if (isset($settings['bootstrap'])) {
            $this->mandatory[] = Config::enginePath($this->root, $settings['bootstrap'], 'Infection bootstrap');
        }
        foreach (($settings['logs'] ?? []) as $path) {
            if (is_string($path)) {
                $this->output($path);
            }
        }
        if (isset($settings['tmpDir']) && is_string($settings['tmpDir'])) {
            $this->output($settings['tmpDir']);
        }
        return $settings;
    }

    private function phpunitInputs(string $configDir, string $name): void
    {
        $path = $configDir . '/' . $name;
        $this->mandatory[] = $path;
        $xml = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$xml->loadXML(Reports::read($path), LIBXML_NONET)) {
                throw new InvalidArgumentException('invalid PHPUnit XML input: ' . $name);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $document = $xml->documentElement;
        if (!$document instanceof DOMElement) {
            throw new InvalidArgumentException('invalid PHPUnit XML input: ' . $name);
        }
        $xpath = new DOMXPath($xml);
        foreach ($xpath->query('//testsuite/directory | //testsuite/file') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $this->roots[] = Config::enginePath($this->root, $this->relativeTo($configDir, trim($node->textContent)), 'PHPUnit testsuite');
            }
        }
        $bootstrap = $document->getAttribute('bootstrap');
        if ($bootstrap !== '') {
            $this->mandatory[] = Config::enginePath($this->root, $this->relativeTo($configDir, $bootstrap), 'PHPUnit bootstrap');
        }
        foreach (['cacheDirectory', 'cacheResultFile'] as $key) {
            $value = $document->getAttribute($key);
            if ($value !== '') {
                $this->output($this->relativeTo($configDir, $value));
            }
        }
        foreach ($xpath->query('//logging//* | //coverage/report//*') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            foreach (['outputFile', 'outputDirectory', 'target'] as $key) {
                if ($node->hasAttribute($key)) {
                    $this->output($this->relativeTo($configDir, $node->getAttribute($key)));
                }
            }
        }
    }

    /**
     * @param list<string> $sourceFiles
     * @return array<string, string> relative path => sha256
     */
    private function hash(array $sourceFiles): array
    {
        $paths = [];
        foreach (array_unique($this->roots) as $path) {
            if ($this->excluded($path)) {
                continue;
            }
            if (is_file($path)) {
                $paths[$path] = true;
                continue;
            }
            foreach ($this->walk($path) as $file) {
                $paths[$file] = true;
            }
        }
        foreach (array_merge($this->mandatory, array_map(fn (string $file): string => $this->root . '/' . $file, $sourceFiles)) as $path) {
            $paths[$path] = true;
        }
        $inputs = [];
        foreach (array_keys($paths) as $path) {
            $real = Config::enginePath($this->root, $path, 'mutation input');
            $relative = substr($real, strlen($this->root) + 1);
            if (!isset($inputs[$relative])) {
                $hash = hash_file('sha256', $real);
                if ($hash === false) {
                    throw new RuntimeException('cannot fingerprint mutation input: ' . $relative);
                }
                $inputs[$relative] = $hash;
            }
        }
        ksort($inputs, SORT_STRING);
        return $inputs;
    }

    /** @return iterable<string> regular files below a directory, skipping links and excluded paths */
    private function walk(string $directory): iterable
    {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => !$file->isLink()
                && !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)
                && !$this->excluded($file->getPathname()),
        );
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    /** Only mutation-relevant settings take part, so unrelated edits do not make measurements stale. */
    private static function configurationHash(array $config): string
    {
        $settings = [
            'fingerprint_format' => 2, 'version' => $config['version'], 'project' => $config['project'],
            'mutator' => array_intersect_key($config['mutator'], array_flip([
                'executable', 'configuration', 'input_paths', 'input_exclude',
            ])),
        ];
        return hash('sha256', Reports::json($settings));
    }

    private function relativeTo(string $directory, string $value): string
    {
        return str_starts_with($value, '/') ? $value : $directory . '/' . $value;
    }

    private function output(string $path): void
    {
        try {
            $this->exclude[] = Config::enginePath($this->root, $path, 'generated output', false);
        } catch (InvalidArgumentException) {
            // External outputs are outside the input manifest already.
        }
    }

    private function excluded(string $path): bool
    {
        foreach ($this->exclude as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
