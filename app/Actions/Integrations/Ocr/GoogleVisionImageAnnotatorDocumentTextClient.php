<?php

namespace App\Actions\Integrations\Ocr;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\TextAnnotation;
use RuntimeException;

class GoogleVisionImageAnnotatorDocumentTextClient implements GoogleVisionDocumentTextClient
{
    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly ?string $projectId = null,
    ) {}

    public function detect(string $contents): GoogleVisionDocumentTextResult
    {
        $client = new ImageAnnotatorClient([
            'credentials' => $this->credentials(),
            'transport' => 'rest',
        ]);

        try {
            $request = new AnnotateImageRequest([
                'image' => new Image(['content' => $contents]),
                'features' => [
                    new Feature(['type' => Type::DOCUMENT_TEXT_DETECTION]),
                ],
            ]);

            $batchRequestOptions = ['requests' => [$request]];

            if ($this->projectId !== null && trim($this->projectId) !== '') {
                $batchRequestOptions['parent'] = 'projects/'.trim($this->projectId);
            }

            $response = $client->batchAnnotateImages(
                new BatchAnnotateImagesRequest($batchRequestOptions),
            );

            $responses = iterator_to_array($response->getResponses());
            $firstResponse = $responses[0] ?? null;

            if ($firstResponse === null) {
                throw new RuntimeException('Google Vision did not return an OCR response.');
            }

            if ($firstResponse->hasError()) {
                $message = trim((string) $firstResponse->getError()?->getMessage());

                throw new RuntimeException($message !== '' ? $message : 'Google Vision returned an OCR error.');
            }

            $annotation = $firstResponse->getFullTextAnnotation();

            return new GoogleVisionDocumentTextResult(
                text: $annotation?->getText(),
                words: $annotation instanceof TextAnnotation ? $this->words($annotation) : [],
                metadata: [
                    'feature' => 'DOCUMENT_TEXT_DETECTION',
                    'project_id' => $this->projectId,
                ],
            );
        } finally {
            $client->close();
        }
    }

    private function credentials(): ServiceAccountCredentials
    {
        if ($this->credentialsPath === null || trim($this->credentialsPath) === '') {
            throw new RuntimeException('Google Vision credentials path is not configured.');
        }

        if (! is_readable($this->credentialsPath)) {
            throw new RuntimeException('Google Vision credentials file is not readable.');
        }

        $json = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! is_array($json)) {
            throw new RuntimeException('Google Vision credentials file is invalid JSON.');
        }

        return new ServiceAccountCredentials(ImageAnnotatorClient::$serviceScopes, $json);
    }

    /**
     * @return list<array{text:string, confidence:float|null}>
     */
    private function words(TextAnnotation $annotation): array
    {
        $words = [];

        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getWords() as $word) {
                        $text = '';

                        foreach ($word->getSymbols() as $symbol) {
                            $text .= (string) $symbol->getText();
                        }

                        $text = trim($text);

                        if ($text === '') {
                            continue;
                        }

                        $words[] = [
                            'text' => $text,
                            'confidence' => max(0.0, min(1.0, (float) $word->getConfidence())),
                        ];
                    }
                }
            }
        }

        return $words;
    }
}
