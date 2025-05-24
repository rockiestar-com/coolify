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
        Schema::table('applications', function (Blueprint $table) {
            $table->string('git_auth_type')->default('ssh_deploy_key');
            $table->string('git_http_username')->nullable();
            $table->text('git_http_password')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('git_auth_type');
            $table->dropColumn('git_http_username');
            $table->dropColumn('git_http_password');
        });
    }
};
