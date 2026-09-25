<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ocr_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('document_hash', 64)->index();
            $table->string('driver', 50);
            $table->string('field_name', 100);
            $table->text('extracted_value')->nullable();
            $table->text('corrected_value')->nullable();
            $table->float('confidence')->nullable();
            $table->json('citation')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|corrected
            $table->unsignedBigInteger('reviewer_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_reviews');
    }
};
