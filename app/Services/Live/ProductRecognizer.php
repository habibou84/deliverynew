<?php

namespace App\Services\Live;

use Anthropic\Client;
use App\Exceptions\BusinessRuleException;
use App\Services\Ai\JibiatAi;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Fiche produit à partir d'une photo : l'IA donne le nom, une description courte et une catégorie (pour comparer
 * les prix des boutiques Jibiat). Seul usage de l'IA dans l'outil live.
 */
class ProductRecognizer
{
    /**
     * @return array{name: string, description: string, category: string, cost_millis: int}
     */
    public function recognize(UploadedFile $photo): array
    {
        $model = (string) config('messaging.ai.model');
        try {
            $client = new Client(apiKey: config('messaging.ai.api_key'));
            $message = $client->beta->messages->create(
                model: $model,
                maxTokens: 600,
                system: 'Tu aides une e-commerçante d\'Abidjan à créer la fiche d\'un produit qu\'elle va vendre en live. '
                    .'À partir de la photo, donne un nom court et clair (3 à 6 mots, en français, avec la couleur ou la matière si on les voit), '
                    .'une description d\'une phrase sans inventer de caractéristique invisible, et une catégorie générale '
                    .'(par exemple « sac à main », « robe », « chaussures », « parfum », « bijoux »).',
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $photo->getMimeType() ?: 'image/jpeg', 'data' => base64_encode((string) file_get_contents($photo->getRealPath()))]],
                        ['type' => 'text', 'text' => 'Remplis la fiche de ce produit.'],
                    ],
                ]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'description', 'category'],
                        'additionalProperties' => false,
                    ]],
                ],
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
            );
        } catch (Throwable $e) {
            report($e);
            throw new BusinessRuleException('L\'assistant ne répond pas pour le moment. Réessayez ou remplissez la fiche vous-même.');
        }

        $data = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
            }
        }
        if (! is_array($data) || $message->stopReason === 'refusal') {
            throw new BusinessRuleException('L\'assistant n\'a pas reconnu ce produit. Reprenez la photo ou remplissez la fiche vous-même.');
        }

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 160),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 500),
            'category' => mb_strtolower(mb_substr(trim((string) ($data['category'] ?? '')), 0, 80)),
            'cost_millis' => app(JibiatAi::class)->costMillis($model, $message->usage->inputTokens ?? 0, $message->usage->outputTokens ?? 0),
        ];
    }
}
