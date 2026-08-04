<?php

namespace AxelFerdinand\StatamicSecretary;

use AxelFerdinand\StatamicSecretary\Commands\DoctorCommand;
use AxelFerdinand\StatamicSecretary\Commands\DryRunCommand;
use AxelFerdinand\StatamicSecretary\Commands\InstallCommand;
use AxelFerdinand\StatamicSecretary\Commands\PruneCommand;
use AxelFerdinand\StatamicSecretary\Commands\RelayRotateRouteCommand;
use AxelFerdinand\StatamicSecretary\Commands\RelayRotateSecretCommand;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Developer\ToolRegistry;
use AxelFerdinand\StatamicSecretary\Events\AgentCompleted;
use AxelFerdinand\StatamicSecretary\Events\ChangeSetPrepared;
use AxelFerdinand\StatamicSecretary\Events\ChangeSetPublished;
use AxelFerdinand\StatamicSecretary\Events\MessageReceived;
use AxelFerdinand\StatamicSecretary\Listeners\QueueSecretaryWebhook;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use AxelFerdinand\StatamicSecretary\OpenAI\ResponsesAgentClient;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        DoctorCommand::class,
        DryRunCommand::class,
        InstallCommand::class,
        PruneCommand::class,
        RelayRotateRouteCommand::class,
        RelayRotateSecretCommand::class,
    ];

    protected $viewNamespace = 'statamic-secretary';

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/secretary.php', 'secretary');

        $this->app['config']->set('mail.mailers.statamic_secretary_postmark', [
            'transport' => 'postmark',
            'token' => (string) $this->app['config']->get('secretary.email.postmark.api_key'),
            'message_stream_id' => (string) $this->app['config']->get('secretary.email.postmark.message_stream', 'outbound'),
        ]);

        $this->app->bind(AgentClient::class, ResponsesAgentClient::class);
        $this->app->singleton(ToolRegistry::class);
        $this->app->scoped(
            OpenAIConfiguration::class,
            static fn (): OpenAIConfiguration => new OpenAIConfiguration,
        );
        $this->app->scoped(
            RelayConfiguration::class,
            static fn (): RelayConfiguration => new RelayConfiguration,
        );
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ((bool) config('secretary.install.auto_migrate', true)) {
            Statamic::afterInstalled(function ($command): void {
                $command->call('secretary:install');
            });
        }

        $this->publishes([
            __DIR__.'/../config/secretary.php' => config_path('secretary.php'),
        ], 'statamic-secretary-config');

        foreach ([
            MessageReceived::class,
            AgentCompleted::class,
            ChangeSetPrepared::class,
            ChangeSetPublished::class,
        ] as $event) {
            Event::listen($event, QueueSecretaryWebhook::class);
        }

        Permission::extend(function (): void {
            Permission::group('secretary', 'Secretary', function (): void {
                Permission::register('access secretary')
                    ->label('Access Secretary')
                    ->children([
                        Permission::make('use secretary')
                            ->label('Ask Secretary to prepare content changes')
                            ->children([
                                Permission::make('publish with secretary')
                                    ->label('Publish content with Secretary'),
                            ]),
                        Permission::make('configure secretary')
                            ->label('Configure Secretary'),
                    ]);
            });
        });

        Nav::extend(function ($nav): void {
            $nav->content('Secretary')
                ->route('secretary.index')
                ->icon('ai-chat-spark')
                ->can('use secretary');
        });
    }
}
