<?php

namespace App\Jobs;

use App\Actions\Integrations\Ocr\ProcessDocumentOcr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocumentOcrJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var int
     */
    public $tries = 5;

    public function __construct(
        public readonly int $documentUploadId,
        public readonly string $parserVersion = 'mock-v1',
    ) {
        $this->onQueue('ocr');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ProcessDocumentOcr $processDocumentOcr): void
    {
        $processDocumentOcr->handle($this->documentUploadId, $this->parserVersion);
    }
}
