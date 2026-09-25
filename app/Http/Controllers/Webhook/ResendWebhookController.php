<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WebhookLog;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Resend\WebhookSignature;

class ResendWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('resend.webhook.secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            WebhookSignature::verify(
                $request->getContent(),
                $this->headers($request),
                $secret,
                (int) config('resend.webhook.tolerance', 300),
            );
        } catch (Exception) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $emailId = is_string($data['email_id'] ?? null) ? $data['email_id'] : null;

        $status = match ($type) {
            'email.sent' => MessageStatus::Sent,
            'email.delivered' => MessageStatus::Delivered,
            'email.bounced' => MessageStatus::Bounced,
            'email.complained', 'email.failed' => MessageStatus::Failed,
            default => null,
        };

        if ($status !== null && $emailId !== null) {
            Message::query()->where('resend_id', $emailId)->update([
                'status' => $status->value,
            ]);
        }

        WebhookLog::query()->create([
            'source' => 'resend',
            'payload' => [
                'type' => $type,
                'email_id' => $emailId,
            ],
            'status' => $status === null ? 'ignored' : 'processed',
        ]);

        return response()->json(['message' => 'Accepted']);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $headers;
    }
}
