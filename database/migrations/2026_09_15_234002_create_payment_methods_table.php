<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway'); 
            $table->string('gateway_customer_id')->nullable();
            $table->string('token'); 
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'gateway', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};