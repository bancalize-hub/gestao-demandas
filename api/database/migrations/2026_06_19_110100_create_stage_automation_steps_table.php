<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Passos ordenados de um playbook: cada um envia texto ou mídia (PDF) após um atraso. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('type')->default('text');           // text | media
            $table->unsignedInteger('delay_minutes')->default(0); // atraso desde a entrada na etapa
            $table->text('text')->nullable();                  // corpo do texto OU legenda do PDF (aceita {nome} etc.)
            $table->string('asset_path')->nullable();          // caminho no disco local (PDF)
            $table->string('asset_mime')->nullable();
            $table->string('asset_filename')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_automation_steps');
    }
};
