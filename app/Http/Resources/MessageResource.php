<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class MessageResource extends MessageSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'body_text' => $this->body_text,
            'body_html' => $this->body_html,
            'headers' => $this->headers,
            'attachments' => $this->attachments ?? [],
            'references' => $this->references,
            'updated_at' => $this->updated_at,
        ];
    }
}
