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
        if (!Schema::hasTable('audits')) {
            Schema::create('audits', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('user_type')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('event');
                $table->morphs('auditable');
                $table->text('old_values')->nullable();
                $table->text('new_values')->nullable();
                $table->text('url')->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->string('user_agent', 1023)->nullable();
                $table->string('tags')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'user_type']);
            });
        } else {
            Schema::table('audits', function (Blueprint $table) {
                // Garantir que todas as colunas necessárias existem
                if (!Schema::hasColumn('audits', 'user_type')) {
                    $table->string('user_type')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('audits', 'event')) {
                    $table->string('event')->after('user_type');
                }
                if (!Schema::hasColumn('audits', 'old_values')) {
                    $table->text('old_values')->nullable();
                }
                if (!Schema::hasColumn('audits', 'new_values')) {
                    $table->text('new_values')->nullable();
                }
                if (!Schema::hasColumn('audits', 'url')) {
                    $table->text('url')->nullable();
                }
                if (!Schema::hasColumn('audits', 'ip_address')) {
                    $table->ipAddress('ip_address')->nullable();
                }
                if (!Schema::hasColumn('audits', 'user_agent')) {
                    $table->string('user_agent', 1023)->nullable();
                }
                if (!Schema::hasColumn('audits', 'tags')) {
                    $table->string('tags')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não vamos fazer nada no down() para preservar os dados
    }
}; 