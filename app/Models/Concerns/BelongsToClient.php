<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models owned by a client. Queries are scoped to the current client, new records join the current client, and a
 * record can never point at another client's records through the relations named in clientConsistentRelations().
 *
 * @phpstan-require-extends Model
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope(new ClientScope);

        // "saving" runs before "creating", so the client is filled in here, ahead of the consistency check.
        static::saving(function (self $model): void {
            if ($model->exists && $model->isDirty('client_id')) {
                throw new LogicException('A '.class_basename($model).' cannot move to another client.');
            }

            $model->client_id ??= app(CurrentClient::class)->id();

            if ($model->client_id === null) {
                throw new LogicException('A '.class_basename($model).' must belong to a client, and no client is current.');
            }

            $model->ensureRelatedRecordsBelongToSameClient();
        });
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Records of every client, for code that deliberately works across clients (Super Admin views, scheduled jobs).
     *
     * @return Builder<static>
     */
    public static function acrossClients(): Builder
    {
        return static::query()->withoutGlobalScope(ClientScope::class);
    }

    /**
     * Belongs-to relations whose records must belong to the same client, such as a campaign's sender identity.
     *
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return [];
    }

    /**
     * Refuses a link to another client's record even when a form or API request was tampered with.
     */
    protected function ensureRelatedRecordsBelongToSameClient(): void
    {
        foreach ($this->clientConsistentRelations() as $relationName) {
            /** @var BelongsTo<Model, $this> $relation */
            $relation = $this->{$relationName}();
            $foreignKey = $relation->getForeignKeyName();

            if (! $this->isDirty($foreignKey) || $this->getAttribute($foreignKey) === null) {
                continue;
            }

            $relatedClientId = $relation->getRelated()->newQueryWithoutScopes()
                ->whereKey($this->getAttribute($foreignKey))
                ->value('client_id');

            if ((int) $relatedClientId !== (int) $this->client_id) {
                throw new LogicException('The selected '.str($relationName)->snake(' ').' belongs to another client.');
            }
        }
    }
}
