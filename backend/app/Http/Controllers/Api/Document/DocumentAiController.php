<?php

namespace App\Http\Controllers\Api\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\AiPreviewRequest;
use App\Http\Requests\Document\SaveOcrDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Ai\DocumentAiService;
use App\Services\Document\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DocumentAiController extends Controller
{
    public function __construct(
        private readonly DocumentAiService $documentAi,
        private readonly DocumentService $documentService,
    ) {}

    public function summarize(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document = $this->documentAi->summarize($document);

        return response()->json([
            'message' => 'Fiche IA générée.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function analyze(Document $document): JsonResponse
    {
        return $this->summarize($document);
    }

    public function ocr(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $text = $this->documentAi->ocr($document);

        return response()->json([
            'message' => 'Texte extrait.',
            'ocr_text' => $text,
        ]);
    }

    public function saveOcr(SaveOcrDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('version', $document);

        $updated = $this->documentService->saveOcrAsVersion(
            source: $document,
            actor: $request->user(),
            text: $request->validated('text'),
        );

        return response()->json([
            'message' => 'Texte OCR enregistré comme nouvelle version.',
            'document' => new DocumentResource($updated),
        ]);
    }

    public function enhance(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $result = $this->documentAi->enhance($document);

        return response()->json([
            'message' => 'Image éclaircie.',
            'mime_type' => $result['mime_type'],
            'image_base64' => base64_encode($result['binary']),
            'file_name' => $result['file_name'],
        ]);
    }

    public function preview(AiPreviewRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'file' => ['Impossible de lire le fichier.'],
            ]);
        }

        $mime = $this->documentAi->mimeFromUpload($file);
        $action = $request->validated('action');

        if ($action === 'ocr') {
            $text = $this->documentAi->ocrBinary($binary, $mime);

            return response()->json([
                'message' => 'Texte extrait.',
                'action' => 'ocr',
                'ocr_text' => $text,
            ]);
        }

        if ($action === 'analyze') {
            $summary = $this->documentAi->analyzeBinary($binary, $mime, $file->getClientOriginalName());

            return response()->json([
                'message' => 'Fiche IA générée.',
                'action' => 'analyze',
                'summary' => $summary,
            ]);
        }

        $result = $this->documentAi->enhanceBinary($binary, $mime);
        $ext = $this->documentAi->extensionFromMime($result['mime_type']);
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'image';

        return response()->json([
            'message' => 'Image éclaircie.',
            'action' => 'enhance',
            'mime_type' => $result['mime_type'],
            'image_base64' => base64_encode($result['binary']),
            'file_name' => $base.'-eclairci.'.$ext,
        ]);
    }
}
