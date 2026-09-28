<?php

namespace App\Http\Resources;

use App\Models\JobCapture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobCapture
 */
class JobCaptureResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'content' => $this->content,
            'file_path' => $this->file_path,
            'error_message' => $this->error_message,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
