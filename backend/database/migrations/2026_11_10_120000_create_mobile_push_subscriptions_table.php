<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Langganan Web Push per perangkat (satu user bisa punya beberapa perangkat). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->integer('id_user')->index();
            $t->char('endpoint_hash', 64)->unique();   // sha256(endpoint) — endpoint terlalu panjang untuk indeks unik
            $t->text('endpoint');
            $t->string('p256dh', 255);
            $t->string('auth', 255);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_subscriptions');
    }
};
