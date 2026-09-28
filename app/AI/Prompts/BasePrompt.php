<?php

namespace App\AI\Prompts;

abstract class BasePrompt
{
    /**
     * Get system instruction for the AI model.
     */
    abstract public function systemInstruction(): string;

    /**
     * Render the user prompt string.
     */
    abstract public function render(): string;

    /**
     * Expected JSON schema or structure description.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [];
    }

    /**
     * Default options when executing this prompt (e.g. temperature).
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'temperature' => 0.1,
            'system_prompt' => $this->systemInstruction(),
        ];
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
