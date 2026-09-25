<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'direction' => $this->direction,
            'from' => $this->from_address,
            'to' => $this->to_address,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'thread_id' => $this->thread_id,
            'in_reply_to' => $this->in_reply_to,
            'is_read' => $this->is_read,
            'resend_id' => $this->resend_id,
            'status' => $this->status,
            'message_id' => $this->message_id,
            'created_at' => $this->created_at,
        ];
    }
}
