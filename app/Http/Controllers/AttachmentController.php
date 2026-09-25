<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    public function download(Message $message, int $index): Response
    {
        $attachments = $message->attachments ?? [];
        $attachment = $attachments[$index] ?? null;

        if (! is_array($attachment) || ! is_string($attachment['data'] ?? null)) {
            abort(404);
        }

        $binary = base64_decode($attachment['data'], true);

        if ($binary === false) {
            abort(404);
        }

        $filename = str_replace(['"', '\\', "\r", "\n"], '', (string) ($attachment['filename'] ?? 'attachment'));
        $mime = is_string($attachment['mime'] ?? null) ? $attachment['mime'] : 'application/octet-stream';

        return response($binary, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
