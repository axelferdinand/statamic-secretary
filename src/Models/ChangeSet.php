<?php

namespace AxelFerdinand\StatamicSecretary\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeSet extends Model
{
    use HasUlids;

    protected $table = 'secretary_change_sets';

    protected $touches = ['conversation'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'patch' => 'array',
            'after' => 'array',
            'review' => 'array',
            'applied_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function proposedByMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'proposed_by_message_id');
    }
}
