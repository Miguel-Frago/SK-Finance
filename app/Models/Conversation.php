<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat. Deliberately NOT scoped to a barangay: people from different barangays can talk to each other.
 * Who may see a chat is decided by its participants (see App\Support\Messaging).
 */
class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function isDirect(): bool
    {
        return $this->type === 'direct';
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public function isAnnouncement(): bool
    {
        return $this->type === 'announcement';
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
