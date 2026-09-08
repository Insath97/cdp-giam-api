<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ProjectIntegration extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'api_base_url',
        'auth_method',
        'client_id',
        'client_secret',
        'encrypted_client_secret',
        'redirect_uris',
        'allowed_user_fields',
        'sync_enabled',
        'sso_enabled',
        'status',
        'last_health_check_at',
        'last_sync_catalog_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_client_secret',
        'client_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'allowed_user_fields' => 'array',
            'sync_enabled' => 'boolean',
            'sso_enabled' => 'boolean',
            'last_health_check_at' => 'datetime',
            'last_sync_catalog_at' => 'datetime',
        ];
    }

    /**
     * Get the project associated with this integration configuration.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Encrypt and store the client secret integration credential.
     */
    public function setClientSecretAttribute(?string $value): void
    {
        $this->attributes['encrypted_client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt and retrieve the plaintext integration credential for outbound requests.
     */
    public function getDecryptedClientSecret(): ?string
    {
        if (empty($this->encrypted_client_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->encrypted_client_secret);
        } catch (\Exception $e) {
            return null;
        }
    }
}
