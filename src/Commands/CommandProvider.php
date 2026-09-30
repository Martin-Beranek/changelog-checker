<?php

declare(strict_types=1);

namespace TastySoul\ChangelogChecker\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * @internal
 */
final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new CheckChangelogCommand(),
        ];
    }
}
