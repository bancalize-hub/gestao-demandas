<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding por empresa (cor de destaque + logos clara/escura) e preferência de
 * tema (claro/escuro) por usuário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('brand_color', 9)->nullable();  // hex da cor de destaque (ex.: #25D366)
            $table->string('logo_light')->nullable();       // logo p/ fundos claros (path em storage/public)
            $table->string('logo_dark')->nullable();        // logo p/ fundos escuros
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 12)->default('light');  // light | dark | system
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'logo_light', 'logo_dark']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
