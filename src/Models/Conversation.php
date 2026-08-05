<?php

namespace AxelFerdinand\StatamicSecretary\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends SecretaryModel
{
    use HasUlids;

    protected $table = 'secretary_conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function changeSets(): HasMany
    {
        return $this->hasMany(ChangeSet::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
