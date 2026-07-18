<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('remote_pollers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('poller_name')->unique();
            $table->string('url');
            $table->boolean('enabled')->default(true);
            $table->string('version')->nullable();
            $table->timestamp('last_contact')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_pollers');
    }
};
