<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Suppliers\EloquentSupplierDirectory;
use FitOut\Extraction\CachingLineItemExtractor;
use FitOut\Extraction\ChunkedLineItemExtractor;
use FitOut\Extraction\LineItemExtractor;
use FitOut\Extraction\LlmLineItemExtractor;
use FitOut\Ingestion\DocumentReader;
use FitOut\Ingestion\Local\LocalDocumentReader;
use FitOut\Llm\Anthropic\AnthropicLlmClient;
use FitOut\Llm\LlmClient;
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
            new ChunkedLineItemExtractor(
                new LlmLineItemExtractor($app->make(LlmClient::class), maxRepairs: config('rfq.llm.max_repairs')),
                config('rfq.llm.chunk_chars'),
            ),
            Cache::store(),
            config('rfq.llm.model').':'.config('rfq.llm.effort'),
        ));

        $this->app->singleton(DocumentReader::class, fn (): DocumentReader => new LocalDocumentReader(
            pdftotext: config('rfq.ingestion.pdftotext'),
            pdftoppm: config('rfq.ingestion.pdftoppm'),
            tesseract: config('rfq.ingestion.tesseract'),
            maxPages: config('rfq.ingestion.max_pages'),
        ));

        $this->app->singleton(SupplierDirectory::class, EloquentSupplierDirectory::class);

        $this->app->bind(SupplierMatcher::class, fn (Application $app): SupplierMatcher => new SupplierMatcher(
            $app->make(SupplierDirectory::class),
            config('rfq.bidders_per_package'),
        ));
    }
}
