<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEmail;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudflareWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configured = config('services.botmail.inbound_secret');
        $given = (string) $request->header('X-Webhook-Secret', '');

        if (! is_string($configured) || $configured === '' || ! hash_equals($configured, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        $log = WebhookLog::query()->create([
            'source' => 'cloudflare',
            'payload' => $this->payloadForLog($payload),
            'status' => 'queued',
        ]);

        ProcessInboundEmail::dispatch($payload, $log->id);

        return response()->json(['message' => 'Accepted'], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function payloadForLog(array $payload): array
    {
        if (! isset($payload['attachments']) || ! is_array($payload['attachments'])) {
            return $payload;
        }

        $payload['attachments'] = array_map(function (mixed $attachment): mixed {
            if (! is_array($attachment)) {
                return $attachment;
            }

            unset($attachment['data']);

            return $attachment;
        }, $payload['attachments']);

        return $payload;
    }
}
