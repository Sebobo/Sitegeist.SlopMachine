<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Infrastructure;

use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
use Sitegeist\Pandora\Domain\AllowedHostNameSourceInterface;

class DomainAllowedHostNameSource implements AllowedHostNameSourceInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function getHostNames(): array
    {
        return array_map(
            fn (Domain $domain): string => $domain->getHostname(),
            array_values($this->domainRepository->findAll()->toArray()),
        );
    }
}
