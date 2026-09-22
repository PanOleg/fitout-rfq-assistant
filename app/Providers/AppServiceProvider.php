<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use FitOut\Extraction\CachingLineItemExtractor;
use FitOut\Extraction\LineItemExtractor;
use FitOut\Extraction\LlmLineItemExtractor;
use FitOut\Llm\Anthropic\AnthropicLlmClient;
use FitOut\Llm\LlmClient;
use FitOut\Suppliers\ArraySupplierDirectory;
use FitOut\Suppliers\SupplierDirectory;
use FitOut\Suppliers\SupplierMatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/** Composition root: the only place that knows which adapter backs which port. */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, fn (): LlmClient => new AnthropicLlmClient(
            new Client(apiKey: config('services.anthropic.key')),
            config('rfq.llm.model'),
            config('rfq.llm.effort'),
        ));

        $this->app->bind(LineItemExtractor::class, fn (Application $app): LineItemExtractor => new CachingLineItemExtractor(
            new LlmLineItemExtractor($app->make(LlmClient::class), maxRepairs: config('rfq.llm.max_repairs')),
            Cache::store(),
            config('rfq.llm.model').':'.config('rfq.llm.effort'),
        ));

        $this->app->singleton(SupplierDirectory::class, fn (): SupplierDirectory => new ArraySupplierDirectory(config('suppliers')));

        $this->app->bind(SupplierMatcher::class, fn (Application $app): SupplierMatcher => new SupplierMatcher(
            $app->make(SupplierDirectory::class),
            config('rfq.bidders_per_package'),
        ));
    }
}
