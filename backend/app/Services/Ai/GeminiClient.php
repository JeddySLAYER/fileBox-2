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
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw ValidationException::withMessages([
                'ai' => ['Clé API Gemini manquante. Ajoutez GEMINI_API_KEY dans le fichier .env.'],
            ]);
        }

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $url = "{$base}/models/{$model}:generateContent";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 4096,
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        try {
            $response = Http::timeout(90)
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

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Réponse Gemini vide ou invalide.');
        }

        return trim($text);
    }
}
