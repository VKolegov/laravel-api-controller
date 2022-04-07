<?php
/**
 * Created by vkolegov in PhpStorm
 * Date: 05/07/2020 12:50
 */

namespace VKolegov\LaravelAPIController\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait HandlesAPIRequest
{

    // TODO: Tests
    /**
     * @param $entityQualifier mixed Либо имя класса, либо отношение (Eloquent Relation)
     * @param Request $r
     * @param array $filterOptions
     * @param callable|null $mappingCallback
     * @param string|null $fieldCase Регистр полей модели
     * @return JsonResponse
     */
    protected function getEntitiesResponse(
        $entityQualifier,
        Request $r,
        array $filterOptions = [],
        callable $mappingCallback = null,
        string $fieldCase = null
    ): JsonResponse
    {
        // Сначала считаем что вообще есть в базе
        // Подсчёт через getCountForPagination() считает сколько всего записей попало в фильтр
        // Без учета разбиения на страницы ( e.g. take() и offset())
        $query = $this->getEntitiesQuery($entityQualifier, $r, $filterOptions, $fieldCase);

        $count = $query->count();


        // Если по нулям - дальше даже не утруждаемся
        if ($count === 0) {
            return new JsonResponse(
                $this->getEntitiesResponseArray(collect(), 0)
            );
        }


        // Если от нас требуется только подсчет
        if ((bool)$r->get('onlyCount') === true) {
            return new JsonResponse(
                $this->getEntitiesResponseArray(collect(), $count)
            );
        }

        // Пагинация
        $query = $this->paginateQuery($query, $r);
        $entities = $query->get();

        return new JsonResponse(
            $this->getEntitiesResponseArray($entities, $count, $mappingCallback)
        );
    }

    /**
     * Формирует Query Builder с учетом запроса
     * @param string|Builder|Relation $entityQualifier Либо имя класса, либо Query Builder, либо отношение (Eloquent Relation)
     * @param Request $r
     * @param array $filterOptions
     * @param string|null $fieldCase Регистр полей модели
     * @return Relation|Builder|\Jenssegers\Mongodb\Helpers\EloquentBuilder
     */
    protected function getEntitiesQuery(
        $entityQualifier,
        Request $r,
        array $filterOptions = [],
        string $fieldCase = null
    )
    {
        /** @var Builder $query */

        if (
            $entityQualifier instanceof Relation
            || $entityQualifier instanceof \Illuminate\Database\Eloquent\Builder
            || $entityQualifier instanceof Builder
        ) {
            $query = $entityQualifier->where($filterOptions);
        } else {
            /**
             * @var Model $entityQualifier
             */
            $query = $entityQualifier::query()->where($filterOptions);
        }

        if (!$fieldCase) {
            $model = $query->getModel();

            if ($model::$snakeAttributes) {
                $fieldCase = 'snake';
            } else {
                $fieldCase = 'camel';
            }
        }

        $this->applySorting($r, $query, $fieldCase);

        // Исключаем айдишники
        $excludeIds = $r->get('excludeIds', []);
        if ($excludeIds) {
            $table = $query->getModel()->getTable();
            $key = $query->getModel()->getKeyName();
            $column = "$table.$key"; // e.g. products.id
            $query->whereNotIn($column, $excludeIds);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Http\Request $r
     * @param Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation $query
     * @param string|null $fieldCase
     * @return void
     */
    protected function applySorting(Request $r, $query, ?string $fieldCase = null)
    {
        $sortBy = $r->get('sortBy');

        if ($sortBy) {
            $sortOrder = $r->get('descending', true) == true ? 'desc' : 'asc';

            switch ($fieldCase) {
                case 'snake':
                    $sortBy = Str::snake($sortBy);
                    break;
                case 'camel':
                    $sortBy = Str::camel($sortBy);
                    break;
                case 'studly':
                    $sortBy = Str::studly($sortBy);
                    break;
                default:
                    break;
            }

            $query->orderBy($sortBy, $sortOrder);
        }
    }

    /**
     * @param Relation|Builder|\Jenssegers\Mongodb\Helpers\EloquentBuilder $query
     * @param Request $r
     * @param int|null $page Optional override
     * @param int|null $itemsByPage Optional override
     * @return Relation|Builder|\Jenssegers\Mongodb\Helpers\EloquentBuilder
     */
    protected function paginateQuery($query, Request $r, int $page = null, int $itemsByPage = null)
    {
        if (!$itemsByPage) {
            $itemsByPage = (int)$r->get('itemsByPage', 20);
            $itemsByPage = min($itemsByPage, 1000); // Жестко ограничиваем тысячей
        }
        if (!$page) {
            $page = (int)$r->get('page', 1);
        }

        $offset = ($page - 1) * $itemsByPage;

        return $query->take($itemsByPage)->skip($offset);
    }

    protected function getEntitiesResponseArray(
        iterable $entities,
        ?int     $count = null,
        callable $mappingCallback = null
    ): array
    {
        // Если задан метод, который выполняет маппинг сущности
        if (is_callable($mappingCallback)) {
            $entities = $entities->map($mappingCallback);
        } else {
            // Если существует метод, который выполняет маппинг сущности
            if (method_exists($this, 'mapEntity')) {
                $entities = $entities->map([$this, 'mapEntity']);
            }
        }

        return [
            'count' => $count ?? count($entities),
            'entities' => $entities
        ];
    }

    protected function getMappedEntity($entityModel, string $id = null, callable $mapMethod = null)
    {
        $entity = $this->getModel($entityModel, $id);

        // Если задан метод, который выполняет маппинг сущности в списке
        if (is_callable($mapMethod)) {
            $mappedEntity = call_user_func($mapMethod, $entity);
        } else {
            $mappedEntity = $entity->jsonSerialize();
        }

        return $mappedEntity;
    }

    protected function getEntity($entityModel, string $id = null, callable $mapMethod = null): JsonResponse
    {
        return new JsonResponse(
            $this->getMappedEntity($entityModel, $id, $mapMethod)
        );
    }

    protected function filterValidationRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $field => $type) {

            switch ($type) {
                case 'bool':
                    $rules[$field] = ['boolean'];
                    break;
                case 'string':
                    $rules[$field] = ['string', 'min:3', 'max:255'];
                    break;
                case 'select':
                    $rules[$field] = ['sometimes', 'array', 'max:20'];
                    $rules["$field.*"] = ['alpha_dash', 'min:1', 'max:100'];
                    break;
                case 'num_range':
                    $rules["{$field}_min"] = ['int'];
                    $rules["{$field}_max"] = ['int', "gte:{$field}_min"];
                    break;
                case 'date_range':
                    $rules["{$field}_min"] = ['date'];
                    $rules["{$field}_max"] = ['date', "gte:{$field}_min"];
                    break;

            }

        }
        return $rules;
    }

    /**
     * @param string $modelName Illuminate\Database\Eloquent\Model class name
     * @param \Illuminate\Http\Request $r
     * @param array $fields key - field to filter by, value = field type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function filterQuery(string $modelName, Request $r, array $fields): \Illuminate\Database\Eloquent\Builder
    {
        // TODO: Make sure its a model class
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $modelName::query();

        // To support filtering by embedded mongodb fields, like 'store.id'
        if ($query->getModel()->getConnection()->getDriverName() !== 'mongodb') {
            $inputFields = $r->validate(
                $this->filterValidationRules($fields)
            );
        } else {
            $inputFields = $r->all();
        }

        if (empty($inputFields)) {
            return $query;
        }

        foreach ($fields as $field => $type) {

            $fieldQ = $inputFields[$field] ?? "";

            switch ($type) {
                case 'bool':

                    $fieldQ = $inputFields[$field] ?? null;
                    if ($fieldQ === null) break;

                    $query->where($field, $fieldQ);
                    break;
                case 'string':

                    if (!$fieldQ) break;

                    $query->where($field, 'like', "%$fieldQ%");
                    break;
                case 'select':

                    if (!is_array($fieldQ)) break;

                    $query->where(function ($q) use ($field, $fieldQ) {

                        foreach ($fieldQ as $selectValue) {
                            $q->orWhere($field, '=', $selectValue);
                        }

                    });

                    break;

                case 'num_range':

                    $fieldMin = $inputFields["{$field}_min"] ?? null;
                    $fieldMax = $inputFields["{$field}_max"] ?? null;

                    if (!is_null($fieldMin)) {
                        $query->where($field, '>=', $fieldMin);
                    }

                    if (!is_null($fieldMax)) {
                        $query->where($field, '<=', $fieldMax);
                    }
                    break;

                case 'date_range':

                    $fieldMin = $inputFields["{$field}_min"] ?? null;
                    $fieldMax = $inputFields["{$field}_max"] ?? null;

                    if (!is_null($fieldMin)) {
                        $minDate = Carbon::parse($fieldMin)->startOfDay();
                        $query->where($field, '>=', $minDate);
                    }

                    if (!is_null($fieldMax)) {
                        $maxDate = Carbon::parse($fieldMax)->endOfDay();
                        $query->where($field, '<=', $maxDate);
                    }

                    break;
            }

        }

        return $query;

    }

    // TODO: Tests
    protected function createEntity(string   $entityModelClass,
                                    array    $attributes = [], array $relationships = [],
                                    callable $mappingCallback = null,
                                    Model    &$entity = null
    ): JsonResponse
    {
        try {
            /** @var Model $entity */
            $entity = $entityModelClass::create($attributes);

            if (!empty($relationships))
                $this->updateRelationships($entity, $attributes, $relationships);

            return $this->successfulEntityModificationResponse(
                $entity,
                $mappingCallback,
                201
            );

        } catch (\Exception $e) {
            \Log::error($e);

            return $this->errorResponse(
                "Внутренняя ошибка при создании новой сущности. {$e->getMessage()}"
            );
        }
    }

    // TODO: Tests
    protected function updateEntity($entityModel,
                                    array $newAttributes = [],
                                    array $newRelationships = [],
        $id = null,
                                    callable $mappingCallback = null
    ): JsonResponse
    {
        $entity = $this->getModel($entityModel, $id);
        $modelName = $modelName ?? 'Сущность ' . class_basename($entity);
        $id = $entity->getKey();

        try {

            if (!$id) {
                throw new \Exception("Entity should exist");
            }

            $entity->fill($newAttributes);

            if (!$entity->save()) {
                return $this->errorResponse(
                    "Не удалось обновить {$modelName} #{$id}"
                );
            }

            if (!empty($newRelationships)) {
                if (!is_array($newRelationships) || !is_array($newRelationships[0])) {
                    throw new \InvalidArgumentException("\$newRelationShips should be 2D array");
                }
                $this->updateRelationships($entity, $newAttributes, $newRelationships);
            }

            return $this->successfulEntityModificationResponse(
                $entity,
                $mappingCallback
            );

        } catch (\Exception $e) {
            \Log::error($e);
            return $this->errorResponse(
                "Не удалось обновить {$modelName} #{$id}",
                [$e->getMessage()]
            );
        }
    }


    // TODO: Tests, refactoring

    /**
     * @param Model $entity Сущность, для которой обновляем отношения
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

    // TODO: Автоматически по классу модели определять её название

    // TODO: Tests
    /**
     * @param string|Model $entityModel FQN or Model instance
     * @param string|int|null $id
     * @param string|null $modelName
     * @return JsonResponse
     * @throws \Exception
     */
    protected function deleteEntity($entityModel, $id = null, string $modelName = null): JsonResponse
    {
        $entity = $this->getModel($entityModel, $id);
        $id = $entity->getKey();

        $modelName = $modelName ?? 'Сущность ' . class_basename($entity);

        // TODO: Lang
        try {
            if ($entity->delete()) {
                // TODO: comply with successfulEntityModificationResponse
                return new JsonResponse([
                    'success' => true,
                    'id' => $id,
                    'deletedEntity' => $entity
                ]);
            } else {
                return $this->errorResponse(
                    "Не удалось удалить {$modelName} #{$id}"
                );
            }
        } catch (\Exception $e) {
            \Log::error($e);

            return $this->errorResponse(
                "Ошибка при удалении {$modelName} #{$id}: " . $e->getMessage()
            );
        }
    }

    // TODO: Tests

    /**
     * @param Model|string $entityModel Model entity or model class
     * @param int|string $id Model ID
     * @return Model
     * @throws \Exception
     */
    protected function getModel($entityModel, $id = null, ?string $getByField = null): Model
    {
        /** @var Model $entity */

        if ($entityModel instanceof Model) {
            $entity = $entityModel;
        } elseif ($id instanceof Model) {
            $entity = $id;
        } elseif (is_string($entityModel) && isset($id)) {
            if (!$getByField) {
                $entity = $entityModel::findOrFail($id);
            } else {
                $entity = $entityModel::where($getByField, $id)->first();

                if (!$entity) {
                    throw (new ModelNotFoundException)->setModel(
                        $entityModel, $id
                    );
                }
            }
        } else {
            throw new \Exception("Entity model should be an instance of Model or a FQN");
        }

        return $entity;
    }

    protected function errorResponse(string $comment, array $errorMessages = [], int $code = 500): JsonResponse
    {
        return new JsonResponse(
            [
                'success' => false,
                'comment' => $comment,
                'errors' => $errorMessages
            ], $code
        );
    }

    protected function successfulEntityModificationResponse(
        $entity,
        callable $mappingCallback = null,
        $code = 200): JsonResponse
    {

        $mappedEntity = $this->getMappedEntity($entity, null, $mappingCallback);

        return new JsonResponse(
            [
                'success' => true,
                'entity' => $mappedEntity
            ], $code
        );
    }
}
