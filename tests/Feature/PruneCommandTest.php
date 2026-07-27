<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;

class PruneCommandTest extends TestCase
{
    public function test_it_only_prunes_conversations_outside_the_requested_retention_window(): void
    {
        $old = Conversation::create(['channel' => 'cp', 'user_id' => 'old']);
        $old->timestamps = false;
        $old->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);
        $recent = Conversation::create(['channel' => 'cp', 'user_id' => 'recent']);

        $this->artisan('secretary:prune', ['--days' => 90, '--force' => true])
            ->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
    }

    public function test_it_rejects_an_invalid_retention_window(): void
    {
        $this->artisan('secretary:prune', ['--days' => 0, '--force' => true])
            ->assertExitCode(2);
    }
}
