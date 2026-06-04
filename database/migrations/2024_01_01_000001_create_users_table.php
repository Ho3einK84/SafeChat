<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 8)->unique();
            $table->string('display_name', 30)->nullable();
            $table->text('avatar')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen')->useCurrent()->useCurrentOnUpdate();
            $table->text('public_key')->nullable();

            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
