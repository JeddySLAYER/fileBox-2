<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GeminiClient
{
    /**
     * @param  list<array<string, mixed>>  $parts  Gemini "parts" (text / inline_data)
     */
    public function generate(array $parts, ?string $systemInstruction = null): string
    {
        $json = $this->request(
            model: (string) config('services.gemini.model', 'gemini-2.0-flash'),
            parts: $parts,
            systemInstruction: $systemInstruction,
            generationConfig: [
                'temperature' => 0.3,
                'maxOutputTokens' => 4096,
            ],
        );

        $text = data_get($json, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Réponse Gemini vide ou invalide.');
        }

        return trim($text);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array{mime_type: string, binary: string}
     */
    public function generateImage(array $parts, ?string $systemInstruction = null): array
    {
        $model = (string) config('services.gemini.image_model', 'gemini-2.5-flash-image');
        $json = $this->request(
            model: $model,
            parts: $parts,
            systemInstruction: $systemInstruction,
            generationConfig: [
                'temperature' => 0.2,
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        );

        $responseParts = data_get($json, 'candidates.0.content.parts', []);
        foreach ($responseParts as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inline) || empty($inline['data'])) {
                continue;
            }

            $binary = base64_decode((string) $inline['data'], true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png');

            return [
                'mime_type' => $mime !== '' ? $mime : 'image/png',
                'binary' => $binary,
            ];
        }

        $fallbackText = data_get($json, 'candidates.0.content.parts.0.text');
        throw ValidationException::withMessages([
            'ai' => [
                is_string($fallbackText) && $fallbackText !== ''
                    ? 'Gemini n’a pas renvoyé d’image : '.$fallbackText
                    : 'Gemini n’a pas renvoyé d’image éclaircie.',
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>
     */
    private function request(
        string $model,
        array $parts,
        ?string $systemInstruction,
        array $generationConfig,
    ): array {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw ValidationException::withMessages([
                'ai' => ['Clé API Gemini manquante. Ajoutez GEMINI_API_KEY dans le fichier .env.'],
            ]);
        }

        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $url = "{$base}/models/{$model}:generateContent";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->asJson()
                ->withQueryParameters(['key' => $apiKey])
                ->post($url, $payload)
                ->throw();
        } catch (RequestException $e) {
            $body = $e->response?->json('error.message') ?? $e->getMessage();
            throw ValidationException::withMessages([
                'ai' => ["Erreur Gemini : {$body}"],
            ]);
        }

        return $response->json() ?? [];
    }
}
