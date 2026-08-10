<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentCreated;

class PrepareDocumentForIndexing
{
    public function handle(DocumentCreated $event): void
    {
        // ponytail: pas d'OCR auto (coût Gemini) — l'utilisateur lance /ai/ocr ou /ai/summarize
        // upgrade: dispatch job si GEMINI_AUTO_OCR=true
    }
}
