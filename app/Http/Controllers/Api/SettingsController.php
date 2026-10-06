<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\UserSettings;
use App\Services\ReadReceiptVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The viewer's notification and privacy settings (Settings-1440).
 *
 * The privacy ones are enforced here on the server, wherever what they
 * hide would otherwise leave it: read state in the read pointers and their
 * broadcasts, last seen in every resource that carries it (see
 * LastSeenVisibility), typing in the typing endpoint. The notification ones
 * are kept here so they follow the person between devices; the client acts
 * on them.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => UserSettings::for($request->user())->toPreferences()]);
    }

    /**
     * Switching read receipts on or off is also recorded where each of the
     * viewer's read pointers stands, in the same transaction: each read
     * counts by the setting it was made under, so the switch only changes
     * what happens from now on (see ReadReceiptVisibility).
     */
    public function update(UpdateSettingsRequest $request, ReadReceiptVisibility $receipts): JsonResponse
    {
        $user = $request->user();
        $settings = UserSettings::for($user);
        $wasSharing = $settings->read_receipts;

        DB::transaction(function () use ($settings, $request, $receipts, $user, $wasSharing): void {
            $settings->fill($request->validated())->save();

            if ($settings->read_receipts !== $wasSharing) {
                $receipts->recordSwitch($user, $settings->read_receipts);
            }
        });

        return response()->json(['data' => $settings->toPreferences()]);
    }
}
