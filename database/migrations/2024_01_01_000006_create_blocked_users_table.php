<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->string('blocker_id', 8);
            $table->string('blocked_id', 8);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['blocker_id', 'blocked_id']);
            $table->index('blocked_id', 'idx_blocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
    }
};
