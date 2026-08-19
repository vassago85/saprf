<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('trigger_description', 255)->nullable()->after('barrel_description');
            $table->string('muzzle_brake_description', 255)->nullable()->after('trigger_description');
            $table->string('bipod_description', 255)->nullable()->after('muzzle_brake_description');
            $table->string('magazine_description', 255)->nullable()->after('bipod_description');
            $table->string('tripod_description', 255)->nullable()->after('magazine_description');
            $table->string('brass_description', 255)->nullable()->after('tripod_description');
            $table->string('powder_description', 255)->nullable()->after('brass_description');
            $table->string('rangefinder_description', 255)->nullable()->after('powder_description');
            $table->string('gunsmith_description', 255)->nullable()->after('rangefinder_description');
            $table->string('scope_mount_description', 255)->nullable()->after('gunsmith_description');
            $table->string('bag_description', 255)->nullable()->after('scope_mount_description');
            $table->string('chronograph_description', 255)->nullable()->after('bag_description');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'trigger_description',
                'muzzle_brake_description',
                'bipod_description',
                'magazine_description',
                'tripod_description',
                'brass_description',
                'powder_description',
                'rangefinder_description',
                'gunsmith_description',
                'scope_mount_description',
                'bag_description',
                'chronograph_description',
            ]);
        });
    }
};
