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
     * Phase 4 (compose/send): a composed message's attachment content comes
     * from an upload, not a live IMAP fetch, so there's no `part_number` to
     * store yet (that column becomes nullable). `storage_path` holds where
     * we kept the uploaded file so it can be attached to the outgoing email
     * and downloaded again later — nullable and left unset for attachments
     * on a *received* message, which still fetch their content live from
     * IMAP by UID + part number when requested (CLAUDE.md §3.3).
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->after('part_number');
            $table->string('part_number', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('storage_path');
            $table->string('part_number', 50)->nullable(false)->change();
        });
    }
};
