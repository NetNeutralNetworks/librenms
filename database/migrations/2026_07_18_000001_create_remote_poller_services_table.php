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
        Schema::create('remote_poller_services', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('remote_poller_id')->index();
            $table->unsignedInteger('service_id')->index();
            $table->tinyInteger('last_status')->default(3);
            $table->text('last_message')->nullable();
            $table->text('last_perf')->nullable();
            $table->timestamp('last_checked')->nullable();
            $table->unique(['remote_poller_id', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_poller_services');
    }
};
