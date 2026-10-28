<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\GuardsConversationHistory;
use App\Http\Controllers\Controller;
use App\Http\Requests\SharedAttachmentsRequest;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\SharedAttachmentResource;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use GuardsConversationHistory;

    /**
     * A conversation's shared media for its info panel: the photos
     * (`kind=media`) or the other files (`kind=files`), newest first, up to
     * `limit` at a time (12 by default, 50 at most) and older than
     * `before_id`, with `meta.total` counting them all.
     *
     * Only what the viewer can see in the history (Message::visibleTo), so
     * a member who left gets what was sent up to then, and nothing from a
     * message deleted since, whose attachments the history doesn't show
     * either. Uploads not yet sent aren't in any conversation.
     *
     * 403 outside the conversation, 404 for a deleted group, as the history.
     */
    public function index(SharedAttachmentsRequest $request, Conversation $conversation): AnonymousResourceCollection|JsonResponse
    {
        if ($denied = $this->denyUnlessReadable($request, $conversation)) {
            return $denied;
        }

        $limit = max(1, min((int) $request->query('limit', 12), 50));
        $beforeId = $request->validated('before_id');

        $visibleMessages = Message::query()
            ->visibleTo($request->user())
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->select('id');

        $shared = Attachment::query()
            ->whereIn('message_id', $visibleMessages)
            ->where('mime_type', $request->wantsMedia() ? 'like' : 'not like', 'image/%');

        $total = (clone $shared)->count();

        // Ordered by id, a UUIDv7, so newest first; and, as with the history,
        // one row past the limit answers "is there more?".
        $page = $shared
            ->when($beforeId !== null, fn (Builder $query): Builder => $query->where('id', '<', $beforeId))
            ->with('message.sender:id,name,avatar_url')
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $page->count() > $limit;
        $page = $page->take($limit)->values();

        return SharedAttachmentResource::collection($page)
            ->additional([
                'meta' => [
                    'total' => $total,
                    'has_more' => $hasMore,
                    'next_before_id' => $hasMore ? $page->last()?->id : null,
                ],
            ]);
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $disk = config('filesystems.default');

        // Random, unguessable key — the original name is kept only in the DB
        // column for display and download.
        $path = $file->store('attachments', $disk);

        [$width, $height] = $this->imageDimensions($file->getRealPath(), $file->getMimeType());

        $attachment = Attachment::create([
            'uploaded_by_user_id' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        return (new AttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Download the attachment under its original filename.
     *
     * Only for someone who can see its message in the history, by the same
     * gate (403 outside the conversation, 404 for a deleted group) and the
     * same rule (Message::visibleTo()): a member who left keeps what was
     * sent before they did, and a deleted message's files are gone for
     * everyone (404). An upload not yet sent belongs to its uploader alone.
     *
     * On object storage (R2) this redirects to a fresh short-lived URL that
     * carries the download disposition. Any other disk streams the file
     * itself: only S3-compatible storage honours `ResponseContentDisposition`,
     * and without it the local disk's signed URL serves the file inline —
     * clicking Download would navigate away from the app to the file.
     */
    public function show(Request $request, Attachment $attachment): RedirectResponse|StreamedResponse|JsonResponse
    {
        $message = $attachment->message;

        if ($message === null) {
            if ($attachment->uploaded_by_user_id !== $request->user()->id) {
                return response()->json(['data' => ['message' => 'Forbidden']], 403);
            }
        } else {
            if ($denied = $this->denyUnlessReadable($request, $message->conversation)) {
                return $denied;
            }

            $isVisible = Message::query()
                ->visibleTo($request->user())
                ->whereKey($message->id)
                ->whereNull('deleted_at')
                ->exists();

            if (! $isVisible) {
                return response()->json(['data' => ['message' => 'Attachment not found']], 404);
            }
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($attachment->disk);

        if (config("filesystems.disks.{$attachment->disk}.driver") !== 's3') {
            return $disk->download($attachment->path, $attachment->original_name);
        }

        $url = $disk->temporaryUrl(
            $attachment->path,
            now()->addMinutes(5),
            ['ResponseContentDisposition' => 'attachment; filename="'.addslashes($attachment->original_name).'"'],
        );

        return redirect()->away($url);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageDimensions(string $realPath, ?string $mimeType): array
    {
        if ($mimeType === null || ! str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($realPath);

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
