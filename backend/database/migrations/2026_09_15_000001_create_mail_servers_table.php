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
        Schema::create('mail_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->enum('imap_encryption', ['ssl', 'tls'])->default('ssl');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->enum('smtp_encryption', ['ssl', 'tls'])->default('ssl');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_servers');
    }
};
