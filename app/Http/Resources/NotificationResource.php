<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `data` is the Phase 6.9 `metadata` payload. The column predates the
     * Phase 6.9 brief (it was created in Phase 5.7) and already carries the
     * generator's structured context, so it is exposed under the column's real
     * name rather than duplicated into a second key.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'title' => $this->title,
            'message' => $this->message,
            // Frontend route the bell deep-links to; null when there is
            // nowhere useful to go.
            'action_url' => $this->action_url,
            'data' => $this->data ?? [],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
