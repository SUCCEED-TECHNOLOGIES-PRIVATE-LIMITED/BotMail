<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CloudflareApiException;
use App\Exceptions\InboxLimitException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InboxResource;
use App\Models\Inbox;
use App\Services\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $inboxes = Inbox::query()
            ->with('domain')
            ->withCount('messages')
            ->latest('id')
            ->paginate($this->perPage($request));

        return InboxResource::collection($inboxes);
    }

    public function store(Request $request, InboxService $inboxes): JsonResponse|InboxResource
    {
        $data = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._+-]*$/'],
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'paused'])],
        ]);

        try {
            $inbox = $inboxes->create($data);
        } catch (InboxLimitException|CloudflareApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $inbox->load('domain')->loadCount('messages');

        return (new InboxResource($inbox))->response()->setStatusCode(201);
    }

    public function show(Inbox $inbox): InboxResource
    {
        $inbox->load('domain')->loadCount('messages');

        return new InboxResource($inbox);
    }

    public function destroy(Inbox $inbox, InboxService $inboxes): JsonResponse
    {
        try {
            $inboxes->delete($inbox);
        } catch (CloudflareApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    protected function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 25)));
    }
}
