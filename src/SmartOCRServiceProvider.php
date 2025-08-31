<?php

namespace LaravelSmartOCR;

use Illuminate\Support\ServiceProvider;
use LaravelSmartOCR\Services\OCRManager;
use LaravelSmartOCR\Services\TemplateManager;
use LaravelSmartOCR\Services\AICleanupService;
use LaravelSmartOCR\Services\DocumentParser;
use LaravelSmartOCR\Console\Commands\CreateTemplateCommand;
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

        $this->app->singleton('smart-ocr.ai-cleanup', function ($app) {
            return new AICleanupService($app);
        });

        $this->app->singleton('smart-ocr.parser', function ($app) {
            return new DocumentParser($app);
        });
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
                ProcessDocumentCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smart-ocr');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}