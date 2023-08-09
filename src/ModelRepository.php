<?php

namespace VKolegov\LaravelAPIController;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * @template T of \Illuminate\Database\Eloquent\Model
 * @phpstan-template T of \Illuminate\Database\Eloquent\Model
 */
class ModelRepository
{
    /**
     * @var string|Model|T
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
     * @return ModelRepository<T>
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

    /**
     * @param T|Model|int|string|null $id Model ID
     * @param string|null $getByField
     * @return T|Model
     * @throws Exception
     */
    public function get($id = null, ?string $getByField = null): Model
    {
        /** @var T|Model $entity */

        if ($id instanceof Model) {
            return $id;
        }

        if (!$getByField) {
            $entity = $this->modelClass::query()->findOrFail($id);
        } else {
            $entity = $this->modelClass::query()->where($getByField, $id)->first();

            if (!$entity) {
                throw (new ModelNotFoundException)->setModel(
                    $this->modelClass, $id
                );
            }
        }

        return $entity;
    }

    public function create(array $attributes = [], array $relationships = []): Model
    {
        $modelName = 'Сущность ' . $this->modelClass;

        try {

            /** @var T|Model $entity */
            $entity = $this->modelClass::query()->create(
                $this->getPureAttributes($attributes, $relationships)
            );

            if (!empty($relationships)) {
                $this->updateRelationships($entity, $attributes, $relationships);
            }

            return $entity;
        } catch (\Throwable $e) {
            throw new Exception(
                "Не удалось создать $modelName: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @param T|Model|int|string $id
     * @param array $newAttributes
     * @param array $newRelationships
     * @return T|Model
     * @throws Exception
     */
    public function update(
        $id,
        array $newAttributes = [],
        array $newRelationships = []
    ): Model
    {

        $modelName = 'Сущность ' . $this->modelClass;

        try {
            $entity = $this->get($id);

            $id = $entity->getKey();

            if (!$id) {
                throw new Exception("Entity should exist");
            }

            $entity->fill(
                $this->getPureAttributes($newAttributes, $newRelationships)
            );

            if (!$entity->save()) {
                throw new Exception(
                    "Не удалось сохранить $modelName #$id"
                );
            }

            if (!empty($newRelationships)) {

                if (!is_array($newRelationships) || !is_array($newRelationships[0])) {
                    throw new InvalidArgumentException("\$newRelationShips should be 2D array");
                }

                $this->updateRelationships($entity, $newAttributes, $newRelationships);
            }

            return $entity;
        } catch (\Throwable $e) {
            throw new Exception(
                "Не удалось обновить $modelName #$id: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    // TODO: Tests

    /**
     * @param T|Model|string|int $id
     * @return bool
     * @throws Exception
     */
    public function delete($id): bool
    {
        $modelName = 'Сущность ' . $this->modelClass;
        try {

            $entity = $this->get($id);
            $id = $entity->getKey();

            // TODO: Lang
            if ($entity->delete()) {
                return true;
            } else {
                throw new Exception("Не удалось удалить $modelName $id");
            }
        } catch (Exception $e) {
            throw new Exception(
                "Не удалось удалить $modelName #$id: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }


    protected function getPureAttributes(array $attributes, array $relationships): array
    {

        if (empty($relationships)) {
            return $attributes;
        }

        $relationshipsField = [];

        foreach ($relationships as $relationship) {
            $attributeName = $relationship['attributeName'] ?? $relationship['name'];

            $relationshipsField[] = $attributeName;
        }

        return array_filter(
            $attributes,
            fn($v, $k) => !in_array($k, $relationshipsField),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param T|Model $entity Сущность, для которой обновляем отношения
     * @param array $attributes Аттрибуты, в которых содержатся новые данные об отношениях
     * Пример: [...,'comments' => ['text' => 'New comment', 'user_id' => 255],...]
     * @param array $relationships Описание отношений и как с ними работать.
     * Каждый элемент массива описывает одно отношение
     * Параметры:
     * - attributeName - имя аттрибута в котором содержатся данные отношения
     * - name - имя отношения
     * - saveMethod - каким методом обновить отношение
     * ( доступные методы: https://laravel.com/docs/6.x/eloquent-relationships#inserting-and-updating-related-models)
     * - clearBeforeSaving - очистить ли перед обновлением старое отношение
     */
    protected function updateRelationships(Model $entity, array $attributes = [], array $relationships = [])
    {
        foreach ($relationships as $relationship) {

            $attributeName = $relationship['attributeName'] ?? $relationship['name'];

            $relationshipName = $relationship['name'];
            $saveMethod = $relationship['saveMethod'];
            $callOnModel = $relationship['callOnModel'] ?? false;

            if (isset($attributes[$attributeName])) {

                if (isset($relationship['clearBeforeSaving']) && $relationship['clearBeforeSaving']) {
                    $entity->$relationshipName()->delete();
                }

                $relationshipData = $attributes[$attributeName];
                if ($callOnModel) {
                    $entity->$saveMethod($relationshipData);
                } else {
                    $entity->$relationshipName()->$saveMethod($relationshipData);
                }

                $entity->load($relationshipName);
            }
        }
    }
}