<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O quadro de tarefas vira um quadro de verdade (nível Trello): listas que a empresa
 * cria, cartão com etiqueta/responsável/checklist/comentário/anexo, ordem dentro da
 * lista e arquivo em vez de exclusão.
 *
 * As 4 listas antigas viviam HARDCODED no front (`TASK_COLS`) e a tarefa guardava o
 * nome da coluna em `tasks.column`. Aqui elas viram linhas em `task_lists`, uma cópia
 * por empresa, e cada tarefa existente é religada à lista correspondente — ninguém
 * perde cartão na virada. `tasks.column` fica no lugar: os follow-ups do robô ainda
 * usam ele ('done' = resolvido) e a ficha do lead ordena por ele.
 */
return new class extends Migration
{
    /** As quatro listas que estavam no código — chave legada => nome e cor. */
    private const PADRAO = [
        ['todo', 'A fazer', '#8696a0'],
        ['doing', 'Em andamento', '#53bdeb'],
        ['review', 'Em revisão', '#ffb443'],
        ['done', 'Concluído', '#25D366'],
    ];

    public function up(): void
    {
        Schema::create('task_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('legacy_column', 16)->nullable();   // só para religar o que existia
            $table->string('name');
            $table->string('color', 16)->default('#8696a0');
            $table->integer('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('name', 60)->default('');
            $table->string('color', 16);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('task_label_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_label_id')->constrained()->cascadeOnDelete();
            $table->unique(['task_id', 'task_label_id']);
        });

        Schema::create('task_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('text', 500);
            $table->boolean('done')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->nullable();          // quem escreveu, se o usuário sumir
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 120)->default('application/octet-stream');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('task_list_id')->nullable()->after('conversation_id')->index();
            // Prazo DE VERDADE (data). O `due` antigo é texto livre ("20/06", "Sem prazo")
            // e continua valendo como legenda de quem veio do formulário do cliente.
            $table->timestamp('due_at')->nullable()->after('due');
            $table->timestamp('archived_at')->nullable()->after('position');
            // Arquivado JUNTO com a lista (e não sozinho): é o que faz restaurar a lista
            // devolver os cartões certos, sem ressuscitar o que alguém tinha arquivado antes.
            $table->boolean('archived_with_list')->default(false)->after('archived_at');
        });

        $this->semear();
    }

    /** Cria as listas padrão por empresa e religa as tarefas que já existiam. */
    private function semear(): void
    {
        $empresas = DB::table('companies')->pluck('id')->all();
        // Tarefa órfã de empresa (base antiga, antes do multi-empresa) também precisa de lista.
        if (DB::table('tasks')->whereNull('company_id')->exists()) {
            $empresas[] = null;
        }

        foreach ($empresas as $companyId) {
            foreach (self::PADRAO as $i => [$chave, $nome, $cor]) {
                $listId = DB::table('task_lists')->insertGetId([
                    'company_id' => $companyId,
                    'legacy_column' => $chave,
                    'name' => $nome,
                    'color' => $cor,
                    'position' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $q = DB::table('tasks')->where('column', $chave);
                $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId);
                $q->update(['task_list_id' => $listId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['task_list_id', 'due_at', 'archived_at', 'archived_with_list']);
        });
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_user');
        Schema::dropIfExists('task_label_task');
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('task_lists');
    }
};
