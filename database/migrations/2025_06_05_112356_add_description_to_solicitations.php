<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDescriptionToSolicitations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('solicitations', function (Blueprint $table) {
            // Add description field if it doesn't exist
            if (!Schema::hasColumn('solicitations', 'description')) {
                $table->text('description')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('solicitations', function (Blueprint $table) {
            // Drop the column if it exists
            if (Schema::hasColumn('solicitations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
} 