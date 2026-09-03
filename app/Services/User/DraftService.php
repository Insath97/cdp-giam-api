<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\UserCreationDraft;
use Illuminate\Support\Str;

class DraftService
{
    public function saveDraft(User $creator, array $formData, int $step, ?string $draftToken = null): UserCreationDraft
    {
        $token = $draftToken ?: (string) Str::uuid();

        return UserCreationDraft::updateOrCreate(
            ['draft_token' => $token],
            [
                'creator_user_id' => $creator->id,
                'current_step' => $step,
                'form_data' => $formData,
                'expires_at' => now()->addDays(7),
            ]
        );
    }

    public function getDraft(string $token, User $creator): ?UserCreationDraft
    {
        return UserCreationDraft::where('draft_token', $token)
            ->where('creator_user_id', $creator->id)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function deleteDraft(string $token, User $creator): bool
    {
        return (bool) UserCreationDraft::where('draft_token', $token)
            ->where('creator_user_id', $creator->id)
            ->delete();
    }
}
