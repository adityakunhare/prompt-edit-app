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
        if (! Schema::hasTable('pixelforge_webhook_events')) {
            Schema::create('pixelforge_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 255);
                $table->string('pixelforge_generation_id');
                $table->json('payload');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('pixelforge_webhook_events', ['event_id'], 'unique')) {
            Schema::table('pixelforge_webhook_events', function (Blueprint $table) {
                $table->unique('event_id', 'pfe_webhook_events_event_id_unique');
            });
        }

        if (! Schema::hasIndex('pixelforge_webhook_events', ['pixelforge_generation_id', 'processed_at'])) {
            Schema::table('pixelforge_webhook_events', function (Blueprint $table) {
                $table->index(
                    ['pixelforge_generation_id', 'processed_at'],
                    'pfe_webhook_events_generation_processed_index',
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pixelforge_webhook_events');
    }
};
