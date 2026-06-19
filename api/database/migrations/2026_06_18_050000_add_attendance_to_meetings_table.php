<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Dono da reunião (conta Google que consulta a Meet API para presença/resumo).
            $table->foreignId('user_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            // Presença real apurada pela Meet REST API após a reunião.
            $table->boolean('attended')->default(false)->after('reminder_sent_at');
            $table->unsignedSmallInteger('attended_minutes')->nullable()->after('attended'); // tempo do lead na sala
            $table->json('attendees')->nullable()->after('attended_minutes');                // [{name, minutes}]
            $table->unsignedSmallInteger('attendance_attempts')->default(0)->after('attendees');
            $table->dateTime('attendance_checked_at')->nullable()->after('attendance_attempts'); // null = ainda não apurado
            // Resumo do que foi conversado (hoje vindo do read.ai por e-mail).
            $table->text('summary')->nullable()->after('attendance_checked_at');
            $table->string('summary_source')->nullable()->after('summary');  // ex.: read.ai
            $table->dateTime('summarized_at')->nullable()->after('summary_source');
        });

        // "Reunião Realizada" estava no cinza padrão; deixa em verde forte para destacar no funil.
        DB::table('stages')->where('key', 'reuniao-realizada')->update(['color' => '#00C853']);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'attended', 'attended_minutes', 'attendees', 'attendance_attempts',
                'attendance_checked_at', 'summary', 'summary_source', 'summarized_at',
            ]);
        });
    }
};
