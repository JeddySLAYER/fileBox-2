<?php

namespace App\Services\Ai;

use App\Models\Document;
use App\Models\Version;
use App\Services\Storage\FileStorageService;
use App\Support\DocumentEditability;
use Illuminate\Http\UploadedFile;
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

    /** @var list<string> */
    private const IMAGE_MIMES = [
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
            $this->briefInstruction(),
        );

        $brief = $this->gemini->generate(
            $parts,
            $this->gedSystemInstruction(),
        );

        $document->summary = $brief;
        $document->ai_analysis = null;
        $document->ai_processed_at = Carbon::now();
        $document->save();

        return $document->fresh(['currentVersion', 'folder', 'author', 'owner']);
    }

    public function analyze(Document $document): Document
    {
        return $this->summarize($document);
    }

    public function ocr(Document $document): string
    {
        $version = $this->currentVersionOrFail($document);

        if (! $this->supportsMultimodal($version)) {
            throw ValidationException::withMessages([
                'ai' => ['L\'OCR Gemini est disponible pour les PDF et images. Convertissez le fichier ou uploadez un scan/PDF.'],
            ]);
        }

        $parts = $this->buildParts(
            $version,
            $this->ocrInstruction(),
            forceFile: true,
        );

        $text = $this->gemini->generate($parts, $this->ocrSystemInstruction());

        $document->ai_processed_at = Carbon::now();
        $document->save();

        return $text;
    }

    /**
     * @return array{mime_type: string, binary: string, file_name: string}
     */
    public function enhance(Document $document): array
    {
        $version = $this->currentVersionOrFail($document);
        if (! $this->supportsImage($version->mime_type, $version->extension)) {
            throw ValidationException::withMessages([
                'ai' => ['L’éclaircissement IA est disponible pour les images (PNG, JPEG, WebP…).'],
            ]);
        }

        $binary = $this->files->get($version->file_path);
        if ($binary === null || $binary === '') {
            throw ValidationException::withMessages([
                'ai' => ['Impossible de lire le fichier.'],
            ]);
        }

        $mime = $this->supportsImage($version->mime_type, null)
            ? $version->mime_type
            : $this->guessMime($version->extension);

        $result = $this->enhanceBinary($binary, $mime);

        $document->ai_processed_at = Carbon::now();
        $document->save();

        $ext = $this->extensionFromMime($result['mime_type']);
        $base = pathinfo((string) $version->file_name, PATHINFO_FILENAME) ?: 'image';

        return [
            'mime_type' => $result['mime_type'],
            'binary' => $result['binary'],
            'file_name' => $base.'-eclairci.'.$ext,
        ];
    }

    public function ocrBinary(string $binary, string $mimeType): string
    {
        $this->assertBinarySize($binary);
        if (! $this->supportsMultimodalMime($mimeType)) {
            throw ValidationException::withMessages([
                'ai' => ['L’OCR est disponible pour les PDF et images.'],
            ]);
        }

        return $this->gemini->generate(
            [
                ['text' => $this->ocrInstruction()],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binary)]],
            ],
            $this->ocrSystemInstruction(),
        );
    }

    /**
     * @return array{mime_type: string, binary: string}
     */
    public function enhanceBinary(string $binary, string $mimeType): array
    {
        $this->assertBinarySize($binary);
        if (! $this->supportsImage($mimeType, null)) {
            throw ValidationException::withMessages([
                'ai' => ['L’éclaircissement IA est disponible pour les images.'],
            ]);
        }

        return $this->gemini->generateImage(
            [
                ['text' => 'Améliore cette image de document scanné : éclaircis le fond, augmente le contraste du texte, réduis le bruit, les ombres et le voile. Ne modifie pas le contenu écrit, ne recadre pas de façon agressive. Produis une image nette, lisible, fond clair.'],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binary)]],
            ],
            'Tu es un outil de restauration de scans. Tu renvoies une image éclaircie.',
        );
    }

    public function analyzeBinary(string $binary, string $mimeType, ?string $fileName = null): string
    {
        $this->assertBinarySize($binary);

        $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
        $parts = [['text' => $this->briefInstruction()]];
        $isText = DocumentEditability::fromExtension($ext)
            || str_starts_with(strtolower($mimeType), 'text/');

        if ($isText) {
            if (trim($binary) === '') {
                throw ValidationException::withMessages([
                    'ai' => ['Le fichier texte est vide.'],
                ]);
            }
            $parts[] = ['text' => "Contenu du document :\n\n".mb_substr($binary, 0, 100_000)];
        } elseif ($this->supportsMultimodalMime($mimeType) || in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => base64_encode($binary),
                ],
            ];
        } else {
            throw ValidationException::withMessages([
                'ai' => [
                    'Ce format n’est pas analysable directement par Gemini. '
                    .'Exportez en PDF ou image, ou utilisez un fichier texte (.txt, .md, .csv).',
                ],
            ]);
        }

        return $this->gemini->generate(
            $parts,
            $this->gedSystemInstruction(),
        );
    }

    public function mimeFromUpload(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $fromExt = $this->guessMime($ext);
        if ($fromExt !== 'application/octet-stream') {
            return $fromExt;
        }

        $detected = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if ($detected !== '' && $detected !== 'application/octet-stream') {
            return $detected;
        }

        throw ValidationException::withMessages([
            'file' => ['Format de fichier non reconnu pour l’IA.'],
        ]);
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

    private function gedSystemInstruction(): string
    {
        return 'Tu es un assistant GED. Tu combines résumé et analyse en une seule fiche, sans inventer de faits. '
            .$this->plainTextFormattingRule();
    }

    private function ocrSystemInstruction(): string
    {
        return 'Tu es un moteur OCR. Tu restitues uniquement le texte extrait. '
            .$this->plainTextFormattingRule();
    }

    private function ocrInstruction(): string
    {
        return 'Extrais tout le texte lisible de ce document (OCR). '
            .'Conserve la structure (titres, listes, paragraphes) avec de simples retours à la ligne. '
            .'Si une zone est illisible, indique [illisible]. Réponds uniquement avec le texte extrait. '
            .$this->plainTextFormattingRule();
    }

    private function briefInstruction(): string
    {
        return "Produis une fiche en français, claire et factuelle, avec exactement ces sections :\n"
            ."1) Résumé (5 à 8 lignes)\n"
            ."2) Points clés (liste avec tirets simples -)\n"
            ."3) Type / nature probable du document\n"
            ."4) Ce qu’il faut retenir pour un collaborateur.\n"
            ."N’invente rien. Si une information n’est pas visible, dis-le.\n"
            .$this->plainTextFormattingRule();
    }

    /** Sortie affichée en texte brut côté UI : pas de Markdown ni d’astérisques. */
    private function plainTextFormattingRule(): string
    {
        return 'Ne mets rien en gras. N’utilise aucun astérisque (*), aucune mise en forme Markdown '
            .'(pas de **, __, #, backticks, listes à puces Markdown), aucune balise HTML. '
            .'Texte brut uniquement : retours à la ligne et tirets simples (-) si besoin.';
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
        $this->assertBinarySize($binary);

        $parts[] = [
            'inline_data' => [
                'mime_type' => $mime,
                'data' => base64_encode($binary),
            ],
        ];

        return $parts;
    }

    private function assertBinarySize(string $binary): void
    {
        $max = (int) config('services.gemini.max_bytes', 12_000_000);
        if (strlen($binary) > $max) {
            throw ValidationException::withMessages([
                'ai' => ['Fichier trop volumineux pour l\'analyse IA (max ~'.round($max / 1_000_000).' Mo).'],
            ]);
        }
    }

    private function supportsMultimodal(Version $version): bool
    {
        return $this->supportsMultimodalMime($version->mime_type)
            || in_array(strtolower((string) $version->extension), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    private function supportsMultimodalMime(?string $mime): bool
    {
        $mime = strtolower((string) $mime);

        return in_array($mime, self::MULTIMODAL_MIMES, true)
            || str_starts_with($mime, 'image/');
    }

    public function supportsImage(?string $mimeType, ?string $extension): bool
    {
        $mime = strtolower((string) $mimeType);
        if (in_array($mime, self::IMAGE_MIMES, true) || str_starts_with($mime, 'image/')) {
            return $mime !== 'image/svg+xml';
        }

        return in_array(strtolower((string) $extension), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    public function extensionFromMime(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
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
