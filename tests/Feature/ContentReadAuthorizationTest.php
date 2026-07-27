<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Content\ContentResourceCatalog;
use AxelFerdinand\StatamicSecretary\Content\EntryCatalog;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

class ContentReadAuthorizationTest extends TestCase
{
    public function test_entry_catalogs_only_return_content_the_user_may_view(): void
    {
        $public = Collection::make('public')->title('Public');
        $public->save();
        $secret = Collection::make('secret')->title('Secret');
        $secret->save();
        Entry::make()->id('public-entry')->collection($public)->slug('public')->data(['title' => 'Public'])->save();
        Entry::make()->id('secret-entry')->collection($secret)->slug('secret')->data(['title' => 'Secret'])->save();
        $user = $this->limitedUser(['view public entries']);
        $catalog = app(EntryCatalog::class);

        $this->assertSame(['public'], collect($catalog->collections($user))->pluck('handle')->all());
        $this->assertSame(['public-entry'], collect($catalog->search($user, ''))->pluck('id')->all());

        $this->expectException(ContentOperationDenied::class);
        $catalog->read($user, 'secret-entry');
    }

    public function test_non_entry_catalogs_enforce_native_term_view_permissions(): void
    {
        $public = Taxonomy::make('topics')->title('Topics');
        $public->save();
        $secret = Taxonomy::make('internal')->title('Internal');
        $secret->save();
        Term::make()->taxonomy($public)->in('default')->data(['title' => 'Public'])->slug('public')->save();
        Term::make()->taxonomy($secret)->in('default')->data(['title' => 'Secret'])->slug('secret')->save();
        $user = $this->limitedUser(['view topics terms']);
        $catalog = app(ContentResourceCatalog::class);

        $this->assertSame(['topics'], collect($catalog->sources($user)['taxonomies'])->pluck('handle')->all());

        $this->expectException(ContentOperationDenied::class);
        $catalog->read($user, 'term', 'internal::secret', 'default');
    }

    public function test_it_refuses_to_send_an_oversized_content_resource_to_the_model(): void
    {
        config()->set('secretary.limits.max_resource_characters', 1000);
        $collection = Collection::make('public')->title('Public');
        $collection->save();
        Entry::make()
            ->id('large-entry')
            ->collection($collection)
            ->slug('large')
            ->data(['title' => 'Large', 'content' => str_repeat('x', 2000)])
            ->save();
        $user = $this->limitedUser(['view public entries']);

        $this->expectException(ContentOperationDenied::class);
        app(EntryCatalog::class)->read($user, 'large-entry');
    }

    public function test_non_super_users_can_search_each_allowed_non_entry_resource_type(): void
    {
        $taxonomy = Taxonomy::make('topics')->title('Topics');
        $taxonomy->save();
        Term::make()->taxonomy($taxonomy)->in('default')->data(['title' => 'Produktnytt'])->slug('product-news')->save();
        $set = GlobalSet::make('company')->title('Company');
        $set->save();
        $set->in('default')->data(['phone' => '11 11 11 11'])->save();
        $nav = Nav::make('main')->title('Main');
        $nav->save();
        $nav->makeTree('default', [['title' => 'Forsiden', 'url' => '/']])->save();
        $user = $this->limitedUser(['view topics terms', 'edit company globals', 'view main nav']);
        $catalog = app(ContentResourceCatalog::class);

        $this->assertSame(['topics::product-news'], collect($catalog->search($user, 'term', 'produkt'))->pluck('resource_id')->all());
        $this->assertSame(['company::default'], collect($catalog->search($user, 'global', 'company'))->pluck('resource_id')->all());
        $this->assertSame(['main::default'], collect($catalog->search($user, 'navigation', 'main'))->pluck('resource_id')->all());
    }

    private function limitedUser(array $permissions)
    {
        $role = Role::make('limited-'.str()->random(8))->permissions($permissions);
        $role->save();
        $user = User::make()->id(str()->random(8).'@example.com')->email(str()->random(8).'@example.com');
        $user->assignRole($role)->save();

        return $user;
    }
}
