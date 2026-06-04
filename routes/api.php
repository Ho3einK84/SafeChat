<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('safechat.ratelimit:init')->post('/init', [AuthController::class, 'init']);

Route::middleware('device.auth')->group(function (): void {
    Route::get('/my-id', [AuthController::class, 'myId']);

    Route::get('/get-public', [MessageController::class, 'getPublic']);
    Route::get('/get-private', [MessageController::class, 'getPrivate']);
    Route::get('/get-group-messages', [MessageController::class, 'getGroup']);
    Route::get('/search-messages', [MessageController::class, 'search']);

    Route::get('/check-user', [UserController::class, 'checkUser']);
    Route::get('/get-block-status', [UserController::class, 'getBlockStatus']);
    Route::get('/get-blocked-users', [UserController::class, 'getBlockedUsers']);
    Route::get('/get-conversations', [UserController::class, 'getConversations']);
    Route::get('/get-profile', [UserController::class, 'getProfile']);
    Route::get('/get-online-users', [UserController::class, 'getOnlineUsers']);
    Route::get('/get-pubkey', [UserController::class, 'getPubkey']);

    Route::get('/get-groups', [GroupController::class, 'index']);
    Route::get('/get-group-key', [GroupController::class, 'getKey']);

    Route::middleware('safechat.csrf')->group(function (): void {
        Route::middleware('safechat.ratelimit:send')->group(function (): void {
            Route::post('/send-public', [MessageController::class, 'sendPublic']);
            Route::post('/send-private', [MessageController::class, 'sendPrivate']);
            Route::post('/send-group-message', [MessageController::class, 'sendGroup']);
        });

        Route::middleware('safechat.ratelimit:unlock')->group(function (): void {
            Route::post('/unlock-message', [MessageController::class, 'unlock']);
        });

        Route::middleware('safechat.ratelimit:mutate')->group(function (): void {
            Route::post('/edit-message', [MessageController::class, 'edit']);
            Route::post('/delete-message', [MessageController::class, 'delete']);
            Route::post('/mark-seen', [MessageController::class, 'markSeen']);

            Route::post('/store-pubkey', [UserController::class, 'storePubkey']);
            Route::post('/update-profile', [UserController::class, 'updateProfile']);
            Route::post('/block-user', [UserController::class, 'blockUser']);
            Route::post('/unblock-user', [UserController::class, 'unblockUser']);

            Route::post('/create-group', [GroupController::class, 'create']);
            Route::post('/add-member', [GroupController::class, 'addMember']);
        });

        Route::middleware('safechat.ratelimit:export')->post('/export-chat', [MessageController::class, 'export']);

        Route::middleware('safechat.ratelimit:admin')->post('/reset-db', [AdminController::class, 'resetDatabase']);
    });
});
