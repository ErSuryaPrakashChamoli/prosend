<?php

namespace App\Models\Scopes;

use App\Services\Tenancy\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current client's rows whenever a current client is known.
 */
class ClientScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $clientId = app(CurrentClient::class)->id();

        if ($clientId !== null) {
            $builder->where($model->qualifyColumn('client_id'), $clientId);
        }
    }
}
