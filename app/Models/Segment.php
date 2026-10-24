<?php

namespace App\Models;

use App\Enums\SegmentMatch;
use App\Services\Segments\SegmentQuery;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'match', 'rules'])]
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'match' => 'all',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match' => SegmentMatch::class,
            'rules' => 'array',
        ];
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The contacts currently matching the segment's rules. Segments are dynamic, so this changes as contacts change.
     *
     * @return Builder<Contact>
     */
    public function contactsQuery(): Builder
    {
        return app(SegmentQuery::class)->apply(Contact::query(), $this);
    }
}
