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
        $table->string('name');
        $table->string('email')->nullable()->unique();
        $table->string('phone')->nullable()->unique();
        $table->string('avatar')->nullable();
        $table->decimal('wallet_balance', 10, 2)->default(0);
        $table->string('stripe_customer_id')->nullable();  
        $table->string('password')->nullable();
        $table->string('role')->default('customer');
        $table->string('provider')->nullable();
        $table->string('provider_id')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->boolean('is_active')->default(true);
        $table->rememberToken();
        $table->softDeletes();
        $table->timestamps();
    });
}

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};