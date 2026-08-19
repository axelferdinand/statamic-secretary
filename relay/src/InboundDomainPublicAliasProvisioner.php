<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class InboundDomainPublicAliasProvisioner implements PublicAliasProvisioner
{
    public function __construct(private RelayAddress $address) {}

    public function provision(Installation $installation): void
    {
        if (! PublicSiteAlias::valid($installation->publicAlias)) {
            throw new RelayRejected('Installation has no valid public email alias.');
        }

        $this->address->publicAddress($installation->publicAlias);
    }
}
