<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCreationDraft extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'creator_user_id',
        'draft_token',
        'current_step',
        'form_data',
        'expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'expires_at' => 'datetime',
            'current_step' => 'integer',
        ];
    }

    /**
     * Get the user who authored this draft.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }
}
