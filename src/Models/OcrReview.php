<?php declare(strict_types=1);
namespace LaravelSmartOCR\Models;

use Illuminate\Database\Eloquent\Model;

class OcrReview extends Model
{
    protected $table = 'ocr_reviews';
    protected $fillable = [
        'document_hash', 'driver', 'field_name', 'extracted_value',
        'corrected_value', 'confidence', 'citation', 'status', 'reviewer_id', 'reviewed_at',
    ];
    protected $casts = [
        'citation'    => 'array',
        'confidence'  => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }

    /** Returns the final value: corrected if available, otherwise extracted. */
    public function final(): mixed
    {
        return $this->corrected_value ?? $this->extracted_value;
    }

    public function approve(?string $reviewerId = null): self
    {
        $this->update(['status' => 'approved', 'reviewer_id' => $reviewerId, 'reviewed_at' => now()]);
        return $this;
    }

    public function correct(string $value, ?string $reviewerId = null): self
    {
        $this->update([
            'status'          => 'corrected',
            'corrected_value' => $value,
            'reviewer_id'     => $reviewerId,
            'reviewed_at'     => now(),
        ]);
        return $this;
    }
}
