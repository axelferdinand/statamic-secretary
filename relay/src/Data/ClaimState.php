<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

enum ClaimState: string
{
    case New = 'new';
    case Processing = 'processing';
    case Complete = 'complete';
    case Conflict = 'conflict';
}
