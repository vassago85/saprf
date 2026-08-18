<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcement attachments live on the dedicated `announcements`
     * disk (private, storage/app/announcements) so they never leak to
     * the public /storage URL. Members can only download via the
     * authenticated controller after we have checked they are on the
     * recipient list.
     */
    public function up(): void
    {
        Schema::create('announcement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('filename', 200);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index('announcement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_attachments');
    }
};
