<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'mobile_push_subscriptions';

    protected $fillable = ['id_user', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'lang', 'user_agent', 'last_sent_at'];
}
