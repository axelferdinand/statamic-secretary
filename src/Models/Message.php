<?php

namespace AxelFerdinand\StatamicSecretary\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends SecretaryModel
{
    use HasUlids;

    protected $table = 'secretary_messages';

    protected $touches = ['conversation'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
