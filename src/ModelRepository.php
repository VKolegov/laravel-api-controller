<?php

namespace VKolegov\LaravelAPIController;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ModelRepository
{
    /**
     * @var string|Model
     */
    private string $modelClass;
    private array $relationshipsToEagerLoad = [];
    private array $allowedFilteringFields = [];
    private ?string $databaseFieldCase = null;

    public function __construct(string $modelClass)
    {

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(
                "$modelClass is not a class extending Illuminate\Database\Eloquent\Model"
            );
        }

        $this->modelClass = $modelClass;
    }

    /**
     * @param Request $r
     * @return Builder|Relation
     * @throws Exception
     */
    public function getQueryFromRequest(Request $r)
    {
        $queryBuilder = new EntitiesRequestQueryBuilder($r, $this->modelClass::query());

        return $queryBuilder
            ->applyFiltering($this->allowedFilteringFields)
            ->applySorting($this->databaseFieldCase)
            ->getQuery()
            ->with($this->relationshipsToEagerLoad);
    }

    public function setEagerLoadingRelationShips(array $relationships): self
    {
        $this->relationshipsToEagerLoad = $relationships;
        return $this;
    }

    /**
     * @param array $allowedFilteringFields
     * @return ModelRepository
     */
    public function setAllowedFilteringFields(array $allowedFilteringFields): ModelRepository
    {
        $this->allowedFilteringFields = $allowedFilteringFields;
        return $this;
    }

    /**
     * @param string|null $databaseFieldCase
     */
    public function setDatabaseFieldCase(?string $databaseFieldCase): void
    {
        $this->databaseFieldCase = $databaseFieldCase;
    }
}