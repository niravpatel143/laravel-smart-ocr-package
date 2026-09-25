<?php

namespace LaravelSmartOCR;

use Illuminate\Support\ServiceProvider;
use LaravelSmartOCR\Services\OCRManager;
use LaravelSmartOCR\Services\TemplateManager;
use LaravelSmartOCR\Services\AICleanupService;
use LaravelSmartOCR\Services\DocumentParser;
use LaravelSmartOCR\Services\DocumentValidator;
use LaravelSmartOCR\Services\UrlSecurityValidator;
use LaravelSmartOCR\Services\RemoteDocumentResolver;
use LaravelSmartOCR\Services\TemporaryDocumentManager;
use LaravelSmartOCR\Console\Commands\CreateTemplateCommand;
use LaravelSmartOCR\Console\Commands\DoctorCommand;
use LaravelSmartOCR\Console\Commands\ProcessDocumentCommand;

class SmartOCRServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smart-ocr.php', 'smart-ocr');

        $this->app->singleton('smart-ocr', function ($app) {
            return new OCRManager($app);
        });

        $this->app->singleton('smart-ocr.templates', function ($app) {
            return new TemplateManager($app);
        });
        // Class-name alias so commands can type-hint TemplateManager directly
        $this->app->alias('smart-ocr.templates', TemplateManager::class);

        $this->app->singleton('smart-ocr.ai-cleanup', function ($app) {
            return new AICleanupService($app);
        });
        $this->app->alias('smart-ocr.ai-cleanup', AICleanupService::class);

        $this->app->singleton('smart-ocr.validator', function ($app) {
            return new DocumentValidator($app['config']->get('smart-ocr', []));
        });

        $this->app->singleton('smart-ocr.url-security', function ($app) {
            return new UrlSecurityValidator();
        });

        $this->app->singleton('smart-ocr.remote-resolver', function ($app) {
            return new RemoteDocumentResolver(
                $app->make('smart-ocr.url-security'),
                $app['config']->get('smart-ocr', [])
            );
        });

        $this->app->bind('smart-ocr.temp-manager', function ($app) {
            return new TemporaryDocumentManager(
                $app['config']->get('smart-ocr.storage.temp_path')
            );
        });

        $this->app->singleton('smart-ocr.parser', function ($app) {
            return new DocumentParser($app);
        });
        $this->app->alias('smart-ocr.parser', DocumentParser::class);
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smart-ocr.php' => config_path('smart-ocr.php'),
            ], 'smart-ocr-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'smart-ocr-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/smart-ocr'),
            ], 'smart-ocr-views');

            $this->commands([
                CreateTemplateCommand::class,
                DoctorCommand::class,
                ProcessDocumentCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smart-ocr');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}