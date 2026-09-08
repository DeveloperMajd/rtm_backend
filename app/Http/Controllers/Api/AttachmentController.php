<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
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
     * Redirect to a fresh short-lived URL, forcing a download with the
     * original filename. Auth-checked so links can't be shared outside the
     * conversation.
     */
    public function show(Request $request, Attachment $attachment): RedirectResponse
    {
        $userId = $request->user()->id;

        $isParticipant = $attachment->message
            && $attachment->message->conversation->participants()
                ->where('user_id', $userId)
                ->exists();

        if (! $isParticipant && $attachment->uploaded_by_user_id !== $userId) {
            abort(403);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($attachment->disk);

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
