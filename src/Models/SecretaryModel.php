<?php

namespace AxelFerdinand\StatamicSecretary\Models;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use Illuminate\Database\Eloquent\Model;

abstract class SecretaryModel extends Model
{
    public function getConnectionName()
    {
        return app(SecretaryDatabase::class)->connectionName();
    }
}
