<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1: nicknames a merchant uses for an account ("عثمان" -> "عثمان ظهير"). Per tenant.
        Schema::create('account_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('alias', 120);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'alias']);
        });

        // Phase 1/2: every understood (or not understood) sentence, privacy-masked, with the signal it produced.
        Schema::create('assistant_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('assistant_message_id')->nullable();
            $table->string('masked_text', 300);
            $table->string('intent', 40)->nullable();   // null = not understood
            $table->string('source', 20);               // answered | confirmed | corrected | unknown
            $table->string('status', 20)->default('new'); // new | disputed | cancelled
            $table->timestamps();

            $table->index(['masked_text', 'intent']);
            $table->index(['status', 'source']);
        });

        // Phase 3: a merchant's own phrasing taught through «ماذا قصدت؟». Applies to that tenant only.
        Schema::create('assistant_personal_phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('masked_text', 300);
            $table->string('intent', 40);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'masked_text']);
        });

        // Phase 2/3: the platform admin's decisions on phrases (global).
        Schema::create('assistant_phrase_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('masked_text', 300);
            $table->string('intent', 40)->nullable();
            $table->string('decision', 20);             // approved | rejected
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['masked_text', 'intent']);
        });

        // Phase 2: every retraining run, so a bad model can be rolled back.
        Schema::create('assistant_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('status', 20);               // active | archived | rejected
            $table->unsignedInteger('template_samples')->default(0);
            $table->unsignedInteger('learned_samples')->default(0);
            $table->decimal('holdout_accuracy', 6, 4)->default(0);
            $table->unsignedInteger('acceptance_passed')->default(0);
            $table->unsignedInteger('acceptance_total')->default(0);
            $table->text('notes')->nullable();
            $table->string('trigger', 20)->default('schedule'); // schedule | admin
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_model_versions');
        Schema::dropIfExists('assistant_phrase_reviews');
        Schema::dropIfExists('assistant_personal_phrases');
        Schema::dropIfExists('assistant_samples');
        Schema::dropIfExists('account_aliases');
    }
};
