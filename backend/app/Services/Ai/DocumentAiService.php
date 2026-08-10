<?php

namespace App\Services\Ai;

use App\Models\Document;
use App\Models\Version;
use App\Services\Storage\FileStorageService;
use App\Support\DocumentEditability;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DocumentAiService
{
    /** @var list<string> */
    private const MULTIMODAL_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly FileStorageService $files,
    ) {}

    public function summarize(Document $document): Document
    {
        $version = $this->currentVersionOrFail($document);
        $parts = $this->buildParts(
            $version,
            'Résume ce document en français de façon claire et structurée (titre implicite, points clés, conclusion courte). Ne invente rien.'
        );

        $summary = $this->gemini->generate($parts, 'Tu es un assistant GED. Tu produis des résumés factuels pour archivage.');

        $document->summary = $summary;
        $document->ai_processed_at = Carbon::now();
        $document->save();

        return $document->fresh(['currentVersion', 'folder', 'author', 'owner']);
    }

    public function analyze(Document $document): Document
    {
        $version = $this->currentVersionOrFail($document);
        $parts = $this->buildParts(
            $version,
            'Analyse ce document en français : type probable, contenu visible, points importants, et explication utile pour un collaborateur qui découvre le fichier. Si le contenu est flou ou partiel, dis-le clairement.'
        );

        $analysis = $this->gemini->generate($parts, 'Tu es un assistant GED. Tu expliques les documents sans inventer de faits non visibles.');

        $document->ai_analysis = $analysis;
        $document->ai_processed_at = Carbon::now();
        $document->save();

        return $document->fresh(['currentVersion', 'folder', 'author', 'owner']);
    }

    public function ocr(Document $document): Document
    {
        $version = $this->currentVersionOrFail($document);

        if (! $this->supportsMultimodal($version)) {
            throw ValidationException::withMessages([
                'ai' => ['L\'OCR Gemini est disponible pour les PDF et images. Convertissez le fichier ou uploadez un scan/PDF.'],
            ]);
        }

        $parts = $this->buildParts(
            $version,
            'Extrais tout le texte lisible de ce document (OCR). Conserve la structure (titres, listes, paragraphes) autant que possible. Si une zone est illisible, indique [illisible]. Réponds uniquement avec le texte extrait.',
            forceFile: true,
        );

        $text = $this->gemini->generate($parts, 'Tu es un moteur OCR. Tu restitues uniquement le texte extrait.');

        $version->ocr_text = $text;
        $version->save();

        // Si pas encore de résumé, propose un résumé court du texte OCR
        if (! $document->summary && mb_strlen($text) > 40) {
            $document->summary = $this->gemini->generate(
                [['text' => "Résume en français ce texte issu d'un OCR :\n\n".mb_substr($text, 0, 12000)]],
                'Tu produis un résumé court pour fiche documentaire.',
            );
        }

        $document->ai_processed_at = Carbon::now();
        $document->save();

        return $document->fresh(['currentVersion', 'folder', 'author', 'owner']);
    }

    private function currentVersionOrFail(Document $document): Version
    {
        $document->loadMissing('currentVersion');
        $version = $document->currentVersion;

        if (! $version || ! $version->file_path) {
            throw ValidationException::withMessages([
                'document' => ['Aucune version de fichier à analyser.'],
            ]);
        }

        if (! $this->files->exists($version->file_path)) {
            throw ValidationException::withMessages([
                'document' => ['Fichier introuvable sur le stockage.'],
            ]);
        }

        return $version;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildParts(Version $version, string $instruction, bool $forceFile = false): array
    {
        $parts = [['text' => $instruction]];

        $isText = DocumentEditability::fromExtension($version->extension)
            || str_starts_with((string) $version->mime_type, 'text/');

        if ($isText && ! $forceFile) {
            $content = $this->files->get($version->file_path) ?? '';
            if (trim($content) === '') {
                throw ValidationException::withMessages([
                    'ai' => ['Le fichier texte est vide.'],
                ]);
            }
            $parts[] = ['text' => "Contenu du document :\n\n".mb_substr($content, 0, 100_000)];

            return $parts;
        }

        if (! $this->supportsMultimodal($version)) {
            throw ValidationException::withMessages([
                'ai' => [
                    'Ce format ('.$version->extension.') n\'est pas analysable directement par Gemini. '
                    .'Exportez en PDF ou image, ou utilisez un fichier texte (.txt, .md, .csv).',
                ],
            ]);
        }

        $binary = $this->files->get($version->file_path);
        if ($binary === null || $binary === '') {
            throw ValidationException::withMessages([
                'ai' => ['Impossible de lire le fichier.'],
            ]);
        }

        $max = (int) config('services.gemini.max_bytes', 12_000_000);
        if (strlen($binary) > $max) {
            throw ValidationException::withMessages([
                'ai' => ['Fichier trop volumineux pour l\'analyse IA (max ~'.round($max / 1_000_000).' Mo).'],
            ]);
        }

        $mime = $version->mime_type ?: $this->guessMime($version->extension);
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mime,
                'data' => base64_encode($binary),
            ],
        ];

        return $parts;
    }

    private function supportsMultimodal(Version $version): bool
    {
        $mime = strtolower((string) $version->mime_type);
        if (in_array($mime, self::MULTIMODAL_MIMES, true)) {
            return true;
        }

        $ext = strtolower((string) $version->extension);

        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    private function guessMime(?string $extension): string
    {
        return match (strtolower((string) $extension)) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }
}
