<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sitegeist\Pandora\Instruction\InstructionsProvider;

/**
 * The instructions that are configured for the roles of the installation.
 *
 * The registry of the MCP server (Sitegeist\Pandora\Instruction\InstructionsRegistry) hands
 * the instructions of the configured providers to the agent that connects. The backend module
 * of the agents shows the same instructions, so that an administrator can see what the agent
 * is told - and where the own entries of the administrators fit in.
 */
#[Flow\Scope('singleton')]
class ConfiguredInstructionsService
{
    public function __construct(
        protected ConfigurationManager $configurationManager,
        protected ObjectManagerInterface $objectManager,
    ) {
    }

    /**
     * The configured providers, with the instructions they provide.
     *
     * @return list<array{role: string, name: string, class: string, instructions: string}>
     */
    public function findConfiguredProviders(): array
    {
        $configuration = $this->configurationManager->getConfiguration('Settings', 'Sitegeist.Pandora.instructions');
        if (!is_array($configuration)) {
            return [];
        }

        $providers = [];
        foreach ($configuration as $role => $providersPerRole) {
            if (!is_array($providersPerRole)) {
                continue;
            }
            foreach ($providersPerRole as $name => $providerClassName) {
                if (!is_string($providerClassName)) {
                    continue;
                }
                $providers[] = [
                    'role' => (string)$role,
                    'name' => (string)$name,
                    'class' => $providerClassName,
                    'instructions' => $this->readInstructions($providerClassName),
                ];
            }
        }

        return $providers;
    }

    /**
     * Whether the provider with the given class is configured for any role.
     */
    public function isProviderConfigured(string $providerClassName): bool
    {
        foreach ($this->findConfiguredProviders() as $provider) {
            if ($provider['class'] === $providerClassName) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instructions of the provider.
     *
     * A provider that cannot be built - because its class does not exist, for example - must
     * not take the whole module down: the administrator should see that something is wrong.
     */
    protected function readInstructions(string $providerClassName): string
    {
        try {
            $provider = $this->objectManager->get($providerClassName);
        } catch (\Throwable $exception) {
            return \sprintf('The provider could not be loaded: %s', $exception->getMessage());
        }

        if (!$provider instanceof InstructionsProvider) {
            return \sprintf('The class "%s" is not an instructions provider.', $providerClassName);
        }

        try {
            return $provider->getInstructions();
        } catch (\Throwable $exception) {
            return \sprintf('The provider could not be read: %s', $exception->getMessage());
        }
    }
}
