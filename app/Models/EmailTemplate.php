<?php

namespace App\Models;

use App\Enums\EmailPurpose;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Services\Sending\TemplateVariables;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category', 'purpose', 'status', 'subject', 'body', 'text_body', 'required_variables'])]
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    /**
     * Changing any of these creates a new version.
     */
    public const CONTENT_FIELDS = ['subject', 'body', 'text_body', 'required_variables'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
    ];

    /**
     * Every saved state of the content is kept as a numbered version.
     */
    protected static function booted(): void
    {
        static::creating(function (EmailTemplate $template): void {
            $template->version = 1;
            $template->created_by ??= auth()->id();
            $template->updated_by ??= auth()->id();
        });

        static::created(fn (EmailTemplate $template) => $template->recordVersion());

        static::updating(function (EmailTemplate $template): void {
            if ($template->isDirty(self::CONTENT_FIELDS)) {
                $template->version = $template->getOriginal('version') + 1;
            }

            $template->updated_by = auth()->id() ?? $template->updated_by;
        });

        static::updated(function (EmailTemplate $template): void {
            if ($template->wasChanged('version')) {
                $template->recordVersion();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'purpose' => EmailPurpose::class,
            'status' => TemplateStatus::class,
            'required_variables' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<EmailTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function hasSentEmail(): bool
    {
        return $this->emailMessages()->exists();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Templates that can be used for new email of the given purpose.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usableFor(Builder $query, EmailPurpose $purpose): void
    {
        $query->where('status', TemplateStatus::Active)->where('purpose', $purpose);
    }

    /**
     * @return list<string>
     */
    public function usedVariables(): array
    {
        return app(TemplateVariables::class)->extract($this->subject, $this->body, $this->text_body);
    }

    public function duplicate(): self
    {
        $copy = $this->replicate(['version', 'created_by', 'updated_by']);
        $copy->name = 'Copy of '.$this->name;
        $copy->status = TemplateStatus::Draft;
        $copy->save();

        return $copy;
    }

    /**
     * Make an earlier version current again. This saves it as a new version, so history is never rewritten.
     */
    public function restore(EmailTemplateVersion $version): void
    {
        $this->update($version->only(self::CONTENT_FIELDS));
    }

    private function recordVersion(): void
    {
        $this->versions()->create([
            ...$this->only(self::CONTENT_FIELDS),
            'version' => $this->version,
            'created_by' => auth()->id(),
        ]);
    }
}
