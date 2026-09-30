<?php

declare(strict_types=1);

namespace TastySoul\ChangelogChecker;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use TastySoul\ChangelogChecker\Commands\CheckChangelogCommand;
use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function trim;

class ChangelogCheckerPlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // This method is called when the plugin is deactivated.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // This method is called when the plugin is uninstalled.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'TastySoul\ChangelogChecker\Commands\CommandProvider',
        ];
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof UpdateOperation) {
            return;
        }

        $packageName = $operation->getTargetPackage()->getName();
        $configuredPlugins = $this->getConfiguredPluginsToCheck();
        if ($configuredPlugins !== [] && !in_array($packageName, $configuredPlugins, true)) {
            return;
        }

        $packageVersion = $operation->getInitialPackage()->getPrettyVersion();
        $selector = $packageName . ':' . $packageVersion;

        $output = CheckChangelogCommand::checkChangelog(false, [$selector]);
        if ($output !== '') {
            $event->getIO()->write('Breaking changes found in: ' . $selector);
            $event->getIO()->write($output);
        }
    }

    /**
     * Reads package filter from root composer.json extra config.
     *
     * Example:
     * "extra": {
     *   "changelog-checker": {
     *     "plugins": ["vendor/package-a", "vendor/package-b"]
     *   }
     * }
     *
     * Empty or missing array means all updated packages are checked.
     *
     * @return list<string>
     */
    private function getConfiguredPluginsToCheck(): array
    {
        if ($this->composer === null) {
            return [];
        }
        $extra = $this->composer->getPackage()->getExtra();

        $config = $extra['changelog-checker'] ?? null;
        if (!is_array($config)) {
            return [];
        }

        $plugins = $config['plugins'] ?? null;
        if (!is_array($plugins)) {
            return [];
        }

        $normalized = [];
        foreach ($plugins as $plugin) {
            if (!is_string($plugin)) {
                continue;
            }

            $value = trim($plugin);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
