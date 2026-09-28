<?php

namespace App\Services\AI\Providers;

use App\Services\AI\DTOs\AIResponseDTO;

interface AIProviderInterface
{
    /**
     * Generate AI completion from prompt.
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO;

    /**
     * Get provider identifier name.
     */
    public function getName(): string;
}
