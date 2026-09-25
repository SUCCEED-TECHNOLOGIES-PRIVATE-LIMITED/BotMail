<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'local_part' => $this->local_part,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'domain_id' => $this->domain_id,
            'domain' => $this->whenLoaded('domain', fn () => $this->domain?->domain),
            'cloudflare_rule_id' => $this->cloudflare_rule_id,
            'messages_count' => $this->whenCounted('messages'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
