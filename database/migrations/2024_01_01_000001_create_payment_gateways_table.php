<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('secret_key')->nullable();
            $table->text('publishable_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('webhook_url')->nullable();
            $table->tinyInteger('status')->default(0)->comment('1 = Active, 0 = Inactive');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};

