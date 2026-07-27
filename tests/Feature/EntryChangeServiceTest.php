<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Content\EntryChangeService;
use AxelFerdinand\StatamicSecretary\Content\EntrySnapshotter;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class EntryChangeServiceTest extends TestCase
{
    public function test_it_uses_a_working_copy_and_only_publishes_after_an_explicit_call(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();

        $entry = Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Gammel tittel', 'content' => 'Original tekst']);
        $entry->save();

        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);

        $changeSet = $service->proposeUpdate(
            $conversation,
            $entry->id(),
            ['title' => 'Ny tittel'],
            'Oppdaterte tittelen'
        );

        $service->applyDraft($changeSet, $user);

        $live = Entry::find('home');
        $this->assertSame('Gammel tittel', $live->value('title'));
        $this->assertTrue($live->hasWorkingCopy());
        $this->assertSame('Ny tittel', $live->fromWorkingCopy()->value('title'));

        $service->publish($changeSet->fresh(), $user);

        $published = Entry::find('home');
        $this->assertSame('Ny tittel', $published->value('title'));
        $this->assertFalse($published->hasWorkingCopy());
        $this->assertSame('published', $changeSet->fresh()->status);
    }

    public function test_it_refuses_to_draft_a_published_entry_without_revisions(): void
    {
        config()->set('statamic.revisions.enabled', false);

        $collection = Collection::make('pages')->title('Pages');
        $collection->save();

        $entry = Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Gammel tittel']);
        $entry->save();

        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $changeSet = $service->proposeUpdate($conversation, 'home', ['title' => 'Ny tittel']);

        $this->expectException(ContentOperationDenied::class);
        $service->applyDraft($changeSet, $user);
    }

    public function test_it_refuses_to_overwrite_a_human_change(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();

        $entry = Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(false)
            ->data(['title' => 'Gammel tittel']);
        $entry->save();

        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $changeSet = $service->proposeUpdate($conversation, 'home', ['title' => 'Sekretærens tittel']);

        Entry::find('home')->set('title', 'Menneskets tittel')->save();

        $this->expectException(ContentConflict::class);
        $service->applyDraft($changeSet, $user);
    }

    public function test_an_update_does_not_add_unrequested_optional_blueprint_fields(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Før'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);

        $draft = $service->proposeUpdate($conversation, 'home', ['title' => 'Etter']);
        $service->applyDraft($draft, $user);
        $data = Entry::find('home')->fromWorkingCopy()->data()->all();

        $this->assertSame('Etter', $data['title']);
        $this->assertArrayNotHasKey('content', $data);
    }

    public function test_it_refuses_to_publish_over_a_live_file_changed_while_a_working_copy_exists(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Publisert'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $draft = $service->proposeUpdate($conversation, 'home', ['title' => 'Secretary-utkast']);
        $service->applyDraft($draft, $user);

        Entry::find('home')->set('title', 'Direkte menneskeendring')->save();

        $this->expectException(ContentConflict::class);
        $service->publish($draft->fresh(), $user);
    }

    public function test_publish_retry_recognizes_the_exact_entry_already_published(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Publisert'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $draft = $service->proposeUpdate($conversation, 'home', ['title' => 'Secretary-utkast']);
        $service->applyDraft($draft, $user);

        Entry::find('home')->publish(['user' => $user]);

        $published = $service->publish($draft->fresh(), $user);

        $this->assertSame('published', $published->status);
        $this->assertSame('Secretary-utkast', Entry::find('home')->value('title'));
    }

    public function test_fingerprints_are_stable_for_associative_key_order(): void
    {
        $snapshotter = app(EntrySnapshotter::class);

        $this->assertSame(
            $snapshotter->fingerprint(['data' => ['title' => 'Hei', 'content' => 'Tekst']]),
            $snapshotter->fingerprint(['data' => ['content' => 'Tekst', 'title' => 'Hei']]),
        );
    }

    public function test_it_creates_an_unpublished_entry_and_only_publishes_after_an_explicit_call(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        $blueprint = $collection->entryBlueprints()->first();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);

        $changeSet = $service->proposeCreate(
            $conversation,
            'pages',
            $blueprint->handle(),
            'default',
            'ny-side',
            ['title' => 'Ny side'],
            summary: 'Opprettet ny side',
        );

        $draft = $service->applyCreateDraft($changeSet, $user);
        $entry = Entry::find($draft->resource_id);

        $this->assertNotNull($entry);
        $this->assertFalse($entry->published());
        $this->assertSame('Ny side', $entry->value('title'));

        $service->publish($draft, $user);

        $this->assertTrue(Entry::find($draft->resource_id)->published());
        $this->assertSame('published', $draft->fresh()->status);
    }

    public function test_create_draft_retry_recognizes_the_exact_entry_already_saved(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        $blueprint = $collection->entryBlueprints()->first();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $changeSet = $service->proposeCreate(
            $conversation,
            'pages',
            $blueprint->handle(),
            'default',
            'ny-side',
            ['title' => 'Ny side'],
        );

        Entry::make()
            ->id($changeSet->resource_id)
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale('default')
            ->slug('ny-side')
            ->published(false)
            ->data((array) $changeSet->after['data'])
            ->updateLastModified($user)
            ->save();

        $draft = $service->applyCreateDraft($changeSet, $user);

        $this->assertSame('draft', $draft->status);
        $this->assertSame($changeSet->resource_id, $draft->resource_id);
        $this->assertSame(1, Entry::query()->where('collection', 'pages')->where('slug', 'ny-side')->count());
    }

    public function test_create_draft_retry_restores_a_missing_structured_parent_node(): void
    {
        $collection = Collection::make('pages')->title('Pages')->structureContents(['max_depth' => 3]);
        $collection->save();
        Entry::make()
            ->id('parent')
            ->collection($collection)
            ->slug('parent')
            ->published(true)
            ->data(['title' => 'Parent'])
            ->save();
        $collection->structure()->makeTree('default', [['entry' => 'parent']])->save();
        $blueprint = $collection->entryBlueprints()->first();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(EntryChangeService::class);
        $changeSet = $service->proposeCreate(
            $conversation,
            'pages',
            $blueprint->handle(),
            'default',
            'child',
            ['title' => 'Child'],
            'parent',
        );
        Entry::make()
            ->id($changeSet->resource_id)
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale('default')
            ->slug('child')
            ->published(false)
            ->data((array) $changeSet->after['data'])
            ->updateLastModified($user)
            ->save();

        $this->assertNull($collection->structure()->in('default')->find($changeSet->resource_id));

        $draft = $service->applyCreateDraft($changeSet, $user);

        $this->assertSame('draft', $draft->status);
        $this->assertSame('parent', Entry::find($changeSet->resource_id)->parent()->id());
        $this->assertNotNull($collection->structure()->in('default')->find($changeSet->resource_id));
    }

    public function test_it_refuses_to_create_beyond_a_collection_structure_max_depth(): void
    {
        $collection = Collection::make('pages')->title('Pages')->structureContents(['max_depth' => 1]);
        $collection->save();
        Entry::make()
            ->id('parent')
            ->collection($collection)
            ->slug('parent')
            ->published(true)
            ->data(['title' => 'Parent'])
            ->save();
        $collection->structure()->makeTree('default', [['entry' => 'parent']])->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);

        $this->expectException(ContentOperationDenied::class);

        app(EntryChangeService::class)->proposeCreate(
            $conversation,
            'pages',
            $collection->entryBlueprints()->first()->handle(),
            'default',
            'child',
            ['title' => 'Child'],
            'parent',
        );
    }
}
