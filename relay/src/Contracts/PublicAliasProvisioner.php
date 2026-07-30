<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;

interface PublicAliasProvisioner
{
    public function provision(Installation $installation): void;
}
