<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reverts the previous migration's `is_user_created` column (CLAUDE.md
     * §8 item 56) — the real fix for "a system folder shouldn't be
     * deletable" turned out to be correcting `FolderSyncService`'s
     * SPECIAL-USE-vs-name-match tie-break (§8 item 57), not a new column;
     * `type === 'custom'` is correct once that underlying classification is
     * fixed.
     */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('is_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('is_user_created')->default(false)->after('type');
        });
    }
};
