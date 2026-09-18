<?php

declare(strict_types=1);

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
        Schema::table('folders', function (Blueprint $table) {
            // Distinct from `type` — a folder synced from the server that
            // FolderTypeMapper couldn't map to a known type (or demoted to
            // `custom` per the dedup rule, CLAUDE.md §8 item 38) is still a
            // real, provider-managed folder (e.g. a standalone `Junk`), not
            // one the user created through this app. Only FolderCreationService
            // sets this true, and only a folder with it true may be deleted.
            $table->boolean('is_user_created')->default(false)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('is_user_created');
        });
    }
};
