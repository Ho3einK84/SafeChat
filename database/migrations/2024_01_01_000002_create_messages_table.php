<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('sender_id', 8);
            $table->string('recipient_id', 8)->nullable();
            $table->text('encrypted_content');
            $table->text('plaintext_content')->nullable();
            $table->boolean('has_password')->default(false);
            $table->string('password_hash')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();

            // Public messages: recipient_id IS NULL AND group_id IS NULL
            // Polling queries: WHERE id > ? ORDER BY id — covered by primary key
            // Conversation queries: sender+recipient pair lookups
            $table->index(['sender_id', 'recipient_id', 'id'], 'idx_conv');
            // Group message queries
            $table->index(['group_id', 'id'], 'idx_group');
            // Sender-only lookups (conversations subquery)
            $table->index('sender_id', 'idx_sender');
            // Public message polling (recipient_id IS NULL, group_id IS NULL, id >)
            $table->index(['recipient_id', 'group_id', 'id'], 'idx_public');
            // Full-text search on plaintext content (public, non-encrypted messages only)
            $table->fullText('plaintext_content', 'idx_public_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
