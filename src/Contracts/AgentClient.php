<?php

namespace AxelFerdinand\StatamicSecretary\Contracts;

use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;

interface AgentClient
{
    public function respond(AgentRequest $request): AgentResponse;
}
