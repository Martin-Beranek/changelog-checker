<?php

declare(strict_types=1);

namespace TastySoul\ChangelogChecker\Commands;

use Composer\Command\BaseCommand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function array_pad;
use function basename;
use function count;
use function ctype_digit;
use function dirname;
use function explode;
use function file;
use function file_exists;
use function file_put_contents;
use function getcwd;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function max;
use function mkdir;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use const FILE_IGNORE_NEW_LINES;
use const PHP_EOL;

/**
 * @internal
 */
final class CheckChangelogCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('changelog:check');
        $this->setDescription('Check changelog for breaking changes');
        $this->addOption('writeToFile', 'w', InputOption::VALUE_NONE, 'Write output to file');
        $this->addOption('all-changes', 'a', InputOption::VALUE_NONE, 'Output all active changelog entries, not only [BC] lines');
        $this->addArgument(
            'packages',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'Packages to check (e.g. vendor/package:1.2.3)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packagesArgument = $input->getArgument('packages');
        if (!is_array($packagesArgument)) {
            $output->writeln('Invalid packages argument.');
            return 1;
        }

        /** @var list<string> $packages */
        $packages = [];
        foreach ($packagesArgument as $package) {
            if (!is_string($package)) {
                continue;
            }

            $packages[] = $package;
        }

        $writeToFile = (bool) $input->getOption('writeToFile');
        $verbose = (bool) $input->getOption('verbose');
        $allChanges = (bool) $input->getOption('all-changes');

        $result = self::checkChangelog($writeToFile, $packages, $verbose, $allChanges);
        $output->writeln($result);

        if (str_starts_with($result, 'Vendor directory not found:')) {
            return 1;
        }

        return 0;
    }

    /**
     * @param list<string> $packages
     */
    public static function checkChangelog(bool $writeToFile, array $packages, bool $verbose = false, bool $allChanges = false): string
    {
        $rootDir = getcwd() ?: '.';
        $vendorDir = realpath($rootDir . '/vendor') ?: $rootDir . '/vendor';
        $outputRoot = $rootDir . '/breaking-changes';

        if (!is_dir($vendorDir)) {
            return $verbose ? 'Vendor directory not found: ' . $vendorDir : '';
        }

        /** @var list<array{0: string, 1: string|null}> $pluginSpecs */
        $pluginSpecs = [];
        foreach ($packages as $package) {
            $pluginSpecs[] = self::parseSelector($package);
        }

        $messages = [];
        $changelogFiles = self::iterChangelogFiles($vendorDir, $pluginSpecs, $messages, $verbose);
        if ($changelogFiles === []) {
            if ($verbose) {
                $messages[] = 'No changelog files found under ' . $vendorDir;
            }
            return implode(PHP_EOL, $messages);
        }

        $versionHeaderRegex = '/^\s*#{1,6}\s*(?:\[)?(?:v)?(\d+(?:\.\d+){1,3}(?:[-+._A-Za-z0-9]+)?)/';
        $foundAny = false;

        foreach ($changelogFiles as [$changelogPath, $minimumVersion]) {
            $relativePath = self::relativePath($changelogPath, $vendorDir);
            if ($relativePath === null) {
                if ($verbose) {
                    $messages[] = 'Skipping path outside vendor: ' . $changelogPath;
                }
                continue;
            }

            $outPath = $outputRoot . '/' . $relativePath;
            $existingLines = [];
            $existingSet = [];

            if ($writeToFile && is_file($outPath)) {
                $lines = file($outPath, FILE_IGNORE_NEW_LINES);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        if ($trimmed === '') {
                            continue;
                        }

                        $existingLines[] = $trimmed;
                        $existingSet[$trimmed] = true;
                    }
                }
            }

            $currentVersion = '<unknown>';
            $newLines = [];
            $active = $minimumVersion === null;

            $sourceLines = file($changelogPath, FILE_IGNORE_NEW_LINES);
            if (!is_array($sourceLines)) {
                continue;
            }

            foreach ($sourceLines as $rawLine) {
                $stripped = trim($rawLine);
                if ($stripped === '') {
                    continue;
                }

                if (preg_match($versionHeaderRegex, $rawLine, $versionMatch) === 1) {
                    $currentVersion = $versionMatch[1];
                    $active = $minimumVersion === null
                        ? true
                        : self::isVersionAtLeast($currentVersion, $minimumVersion);
                    continue;
                }

                if (!$active) {
                    continue;
                }

                if (!$allChanges && stripos($stripped, '[bc]') === false) {
                    continue;
                }

                $entry = $currentVersion . ' | ' . $stripped;
                if (isset($existingSet[$entry])) {
                    continue;
                }

                $newLines[] = $entry;
                $existingSet[$entry] = true;
                $existingLines[] = $entry;
            }

            if ($newLines === []) {
                continue;
            }

            if ($writeToFile) {
                $outDir = dirname($outPath);
                if (!is_dir($outDir)) {
                    mkdir($outDir, 0777, true);
                }

                file_put_contents($outPath, implode(PHP_EOL, $existingLines) . PHP_EOL);
            }

            $foundAny = true;
            foreach ($newLines as $line) {
                if ($allChanges) {
                    $messages[] = $line;
                } else {
                    $messages[] = '<error>' . $line . '</error>';
                }
            }
        }

        if (!$foundAny && $verbose) {
            $messages[] = 'No new breaking-change entries found.';
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * @param list<array{0: string, 1: string|null}> $pluginSpecs
     * @param list<string> $messages
     * @return list<array{0: string, 1: string|null}>
     */
    private static function iterChangelogFiles(string $vendorDir, array $pluginSpecs, array &$messages, bool $verbose = false): array
    {
        $results = [];
        $seen = [];

        if ($pluginSpecs === []) {
            foreach (self::findChangelogFiles($vendorDir) as $path) {
                $results[] = [$path, null];
            }

            return $results;
        }

        foreach ($pluginSpecs as [$selector, $targetVersion]) {
            $target = self::resolveTarget($selector, $vendorDir);
            if (!file_exists($target)) {
                if ($verbose) {
                    $messages[] = 'Plugin path not found: ' . $selector;
                }
                continue;
            }

            $candidates = [];
            if (is_file($target)) {
                $candidates[] = $target;
            } elseif (is_dir($target)) {
                $candidates = self::findChangelogFiles($target);
            }

            foreach ($candidates as $candidate) {
                if (strtolower(basename($candidate)) !== 'changelog.md') {
                    continue;
                }

                $key = $candidate . '|' . ($targetVersion ?? '');
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $results[] = [$candidate, $targetVersion];
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private static function findChangelogFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            if (strtolower($fileInfo->getFilename()) !== 'changelog.md') {
                continue;
            }

            $realPath = $fileInfo->getRealPath();
            if ($realPath !== false) {
                $paths[] = $realPath;
            }
        }

        sort($paths);
        return $paths;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function parseSelector(string $selector): array
    {
        $separatorPosition = strrpos($selector, ':');
        if ($separatorPosition === false) {
            return [$selector, null];
        }

        $pluginPart = substr($selector, 0, $separatorPosition);
        $versionPart = substr($selector, $separatorPosition + 1);
        if ($pluginPart === '' || $versionPart === '') {
            return [$selector, null];
        }

        if (preg_match('/^[vV]?\d+(?:\.\d+){0,2}(?:[-+._A-Za-z0-9]+)?$/', $versionPart) !== 1) {
            return [$selector, null];
        }

        return [$pluginPart, $versionPart];
    }

    private static function resolveTarget(string $pathString, string $vendorDir): string
    {
        if (self::isAbsolutePath($pathString)) {
            return $pathString;
        }

        if (str_starts_with($pathString, 'vendor/')) {
            return $vendorDir . '/' . substr($pathString, strlen('vendor/'));
        }

        return $vendorDir . '/' . $pathString;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function isVersionAtLeast(string $currentVersion, string $minimumVersion): bool
    {
        $currentParts = self::parseVersion($currentVersion);
        $minimumParts = self::parseVersion($minimumVersion);

        if ($currentParts === null || $minimumParts === null) {
            return true;
        }

        $length = max(count($currentParts), count($minimumParts));
        $currentParts = array_pad($currentParts, $length, 0);
        $minimumParts = array_pad($minimumParts, $length, 0);

        for ($index = 0; $index < $length; $index++) {
            if ($currentParts[$index] > $minimumParts[$index]) {
                return true;
            }

            if ($currentParts[$index] < $minimumParts[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>|null
     */
    private static function parseVersion(string $value): ?array
    {
        $value = ltrim(trim($value), 'vV');
        $core = explode('-', $value, 2)[0];
        $core = explode('+', $core, 2)[0];

        $parts = [];
        foreach (explode('.', $core) as $part) {
            if (ctype_digit($part)) {
                $parts[] = (int) $part;
            }
        }

        return $parts === [] ? null : $parts;
    }

    private static function relativePath(string $path, string $base): ?string
    {
        $normalizedPath = str_replace('\\\\', '/', $path);
        $normalizedBase = rtrim(str_replace('\\\\', '/', $base), '/');

        if ($normalizedPath === $normalizedBase) {
            return '';
        }

        $prefix = $normalizedBase . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            return null;
        }

        return substr($normalizedPath, strlen($prefix));
    }
}
