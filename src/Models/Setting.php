<?php

namespace AxelFerdinand\StatamicSecretary\Models;

final class Setting extends SecretaryModel
{
    protected $table = 'secretary_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted:array',
        ];
    }
}
