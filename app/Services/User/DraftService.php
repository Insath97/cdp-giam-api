<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\UserCreationDraft;
use Illuminate\Support\Str;

/**
 * Service managing temporary multi-step user creation wizard drafts.
 *
 * Draft states are persisted in the MySQL database table `user_creation_drafts` via
 * the UserCreationDraft Eloquent model with a 7-day expiration (`expires_at`).
 * They are not stored in Redis or generic cache.
 */
class DraftService
{
    /**
     * Save or update a draft state.
     *
     * @param User $creator
     * @param array<string, mixed> $formData
     * @param int $step
     * @param string|null $draftToken
     * @return UserCreationDraft
     */
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

    /**
     * Retrieve an active unexpired draft for the creator.
     *
     * @param string $token
     * @param User $creator
     * @return UserCreationDraft|null
     */
    public function getDraft(string $token, User $creator): ?UserCreationDraft
    {
        return UserCreationDraft::where('draft_token', $token)
            ->where('creator_user_id', $creator->id)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Delete an active draft.
     *
     * @param string $token
     * @param User $creator
     * @return bool
     */
    public function deleteDraft(string $token, User $creator): bool
    {
        return (bool) UserCreationDraft::where('draft_token', $token)
            ->where('creator_user_id', $creator->id)
            ->delete();
    }
}
