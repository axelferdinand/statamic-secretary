<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Content\ContentResourceCatalog;
use AxelFerdinand\StatamicSecretary\Content\StagedContentChangeService;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

class StagedContentChangeServiceTest extends TestCase
{
    public function test_it_stages_a_term_without_touching_content_and_publishes_explicitly(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        Term::make()->taxonomy($taxonomy)->in('default')->data(['title' => 'Før'])->slug('news')->save();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);

        $draft = $service->proposeUpdate(
            $conversation,
            'term',
            'topics::news',
            'default',
            ['title' => 'Etter'],
            $user,
            'Oppdaterte emnet',
        );

        $this->assertSame('draft', $draft->status);
        $this->assertSame('Før', Term::find('topics::news')->in('default')->title());

        $service->publish($draft, $user);

        $this->assertSame('Etter', Term::find('topics::news')->in('default')->title());
        $this->assertSame('published', $draft->fresh()->status);
    }

    public function test_a_non_super_editor_can_publish_a_term_update_with_statamics_native_edit_permission(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        Term::make()->taxonomy($taxonomy)->in('default')->data(['title' => 'Før'])->slug('news')->save();
        $role = Role::make('term-editor')->permissions(['edit topics terms']);
        $role->save();
        $user = User::make()->id('term-editor@example.com')->email('term-editor@example.com');
        $user->assignRole($role)->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(StagedContentChangeService::class);

        $draft = $service->proposeUpdate(
            $conversation,
            'term',
            'topics::news',
            'default',
            ['title' => 'Etter'],
            $user,
        );
        $service->publish($draft, $user);

        $this->assertSame('Etter', Term::find('topics::news')->in('default')->title());
    }

    public function test_it_stages_global_values_and_detects_a_human_edit_before_publish(): void
    {
        $set = GlobalSet::make('company')->title('Company');
        $set->save();
        $set->in('default')->data(['phone' => '11 11 11 11'])->save();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);
        $draft = $service->proposeUpdate(
            $conversation,
            'global',
            'company::default',
            'default',
            ['phone' => '22 22 22 22'],
            $user,
        );

        $this->assertSame('11 11 11 11', GlobalSet::findByHandle('company')->in('default')->get('phone'));

        GlobalSet::findByHandle('company')->in('default')->data(['phone' => '33 33 33 33'])->save();

        $this->expectException(ContentConflict::class);
        $service->publish($draft, $user);
    }

    public function test_publish_retry_recognizes_an_exact_already_applied_global_change(): void
    {
        $set = GlobalSet::make('company')->title('Company');
        $set->save();
        $set->in('default')->data(['phone' => '11'])->save();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);
        $draft = $service->proposeUpdate(
            $conversation,
            'global',
            'company::default',
            'default',
            ['phone' => '22'],
            $user,
        );
        GlobalSet::findByHandle('company')->in('default')->data(['phone' => '22'])->save();

        $published = $service->publish($draft, $user);

        $this->assertSame('published', $published->status);
        $this->assertSame('22', GlobalSet::findByHandle('company')->in('default')->get('phone'));
    }

    public function test_it_stages_and_publishes_a_complete_navigation_tree(): void
    {
        $nav = Nav::make('main')->title('Main');
        $nav->save();
        $nav->makeTree('default', [[
            'id' => 'old',
            'title' => 'Før',
            'url' => '/for',
        ]])->save();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);

        $draft = $service->proposeUpdate(
            $conversation,
            'navigation',
            'main::default',
            'default',
            ['tree' => [[
                'id' => 'old',
                'title' => 'Etter',
                'url' => '/etter',
            ]]],
            $user,
        );

        $this->assertSame('Før', Nav::findByHandle('main')->in('default')->tree()[0]['title']);

        $service->publish($draft, $user);

        $tree = Nav::findByHandle('main')->in('default')->tree();
        $this->assertSame('Etter', $tree[0]['title']);
        $this->assertSame('/etter', $tree[0]['url']);
    }

    public function test_it_rejects_unsafe_navigation_urls_before_creating_a_draft(): void
    {
        $nav = Nav::make('main')->title('Main');
        $nav->save();
        $nav->makeTree('default', [])->save();
        [$conversation, $user] = $this->context();

        $this->expectException(ContentOperationDenied::class);

        app(StagedContentChangeService::class)->proposeUpdate(
            $conversation,
            'navigation',
            'main::default',
            'default',
            ['tree' => [['title' => 'Farlig', 'url' => 'javascript:alert(1)']]],
            $user,
        );
    }

    public function test_navigation_cannot_add_an_entry_the_editor_may_not_view(): void
    {
        $collection = Collection::make('private')->title('Private');
        $collection->save();
        Entry::make()
            ->id('private-entry')
            ->collection($collection)
            ->slug('private-entry')
            ->data(['title' => 'Private'])
            ->save();
        $nav = Nav::make('main')->title('Main');
        $nav->save();
        $nav->makeTree('default', [])->save();
        $role = Role::make('nav-editor')->permissions(['edit main nav']);
        $role->save();
        $user = User::make()->id('nav-editor@example.com')->email('nav-editor@example.com');
        $user->assignRole($role)->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);

        $this->expectException(ContentOperationDenied::class);

        app(StagedContentChangeService::class)->proposeUpdate(
            $conversation,
            'navigation',
            'main::default',
            'default',
            ['tree' => [['entry' => 'private-entry']]],
            $user,
        );
    }

    public function test_it_stages_a_new_term_and_only_creates_it_on_publish(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        $blueprint = $taxonomy->termBlueprints()->first();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);

        $draft = $service->proposeTermCreate(
            $conversation,
            'topics',
            $blueprint->handle(),
            'default',
            'produktnytt',
            ['title' => 'Produktnytt'],
            $user,
        );

        $this->assertNull(Term::find('topics::produktnytt'));

        $service->publish($draft, $user);

        $this->assertSame('Produktnytt', Term::find('topics::produktnytt')->in('default')->title());
    }

    public function test_a_non_super_editor_can_publish_a_new_term_with_statamics_native_create_permission(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        $role = Role::make('term-creator')->permissions(['view topics terms', 'create topics terms']);
        $role->save();
        $user = User::make()->id('term-creator@example.com')->email('term-creator@example.com');
        $user->assignRole($role)->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $service = app(StagedContentChangeService::class);
        $draft = $service->proposeTermCreate(
            $conversation,
            'topics',
            $taxonomy->termBlueprints()->first()->handle(),
            'default',
            'produktnytt',
            ['title' => 'Produktnytt'],
            $user,
        );

        $service->publish($draft, $user);

        $this->assertSame('Produktnytt', Term::find('topics::produktnytt')->in('default')->title());
    }

    public function test_term_create_publish_retry_recognizes_the_exact_created_term(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        $blueprint = $taxonomy->termBlueprints()->first();
        [$conversation, $user] = $this->context();
        $service = app(StagedContentChangeService::class);
        $draft = $service->proposeTermCreate(
            $conversation,
            'topics',
            $blueprint->handle(),
            'default',
            'produktnytt',
            ['title' => 'Produktnytt'],
            $user,
        );
        Term::make()
            ->taxonomy($taxonomy)
            ->blueprint($blueprint->handle())
            ->in('default')
            ->data((array) $draft->after['data'])
            ->slug('produktnytt')
            ->save();

        $current = app(ContentResourceCatalog::class)->read($user, 'term', 'topics::produktnytt', 'default');
        $this->assertSame($draft->after, collect($current)->except('fingerprint')->all());

        $published = $service->publish($draft, $user);

        $this->assertSame('published', $published->status);
    }

    /** @return array{0: Conversation, 1: mixed} */
    private function context(): array
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        return [
            Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]),
            $user,
        ];
    }
}
