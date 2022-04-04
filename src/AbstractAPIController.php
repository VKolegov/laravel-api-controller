<?php
/**
 * Created by vkolegov in PhpStorm
 * Date: 20/07/2021 20:14
 */

namespace VKolegov\LaravelAPIController;


use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use VKolegov\LaravelAPIController\Requests\APIEntitiesRequest;
use VKolegov\LaravelAPIController\Traits\ExportsFilteredEntities;
use VKolegov\LaravelAPIController\Traits\HandlesAPIRequest;

abstract class AbstractAPIController extends Controller
{
    use HandlesAPIRequest, ExportsFilteredEntities;

    public const MODEL_CLASS = Model::class;
    public const GET_MODEL_BY = null;
    public const MODEL_RELATIONSHIPS = [];
    /**
     * @deprecated
     */
    public const SEARCHABLE_FIELDS = [];
    public const FILTER_FIELDS = [];
    public const EAGER_LOAD_RELATIONSHIPS = [];
    public const EXPORT_EAGER_LOAD_RELATIONSHIPS = [];

    public function index(APIEntitiesRequest $r): JsonResponse
    {
        if (count(static::FILTER_FIELDS) > 0) {
            $query = $this->filterQuery(
                static::MODEL_CLASS,
                $r,
                static::FILTER_FIELDS,
            );
        } else {
            $query = (static::MODEL_CLASS)::query();
        }

        if (count(static::EAGER_LOAD_RELATIONSHIPS) > 0) {
            $query->with(static::EAGER_LOAD_RELATIONSHIPS);
        }


        if (count(static::SEARCHABLE_FIELDS) === 0) {
            return $this->getEntitiesResponse(
                $query,
                $r,
            );
        } else {
            return $this->getSearchableEntities(
                $query,
                $r,
                static::SEARCHABLE_FIELDS,
            );
        }
    }

    /**
     * @throws Exception
     */
    public function show($entity): JsonResponse
    {
        $model = $this->getModel(
            static::MODEL_CLASS,
            $entity,
            static::GET_MODEL_BY,
        );

        $this->entityAccessHook($model);

        return $this->getEntity($model, null, [$this, 'mapSingleEntity']);
    }

    public function store(Request $r): JsonResponse
    {
        $attributes = $r->validate(
            $this->validationRules()
        );

        try {
            $this->preCreateHook($attributes);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorResponse(
                $e->getMessage(),
                [],
                400
            );
        }

        $createEntityResponse = $this->createEntity(
            static::MODEL_CLASS,
            $attributes,
            static::MODEL_RELATIONSHIPS,
            [$this, 'mapSingleEntity'],
            $entity,
        );

        try {
            $this->postCreateHook($entity);
        } catch (Throwable $e) {
            return $this->errorResponse(
                $e->getMessage(),
                [],
                400
            );
        }

        return $createEntityResponse;
    }

    /**
     * @param array $attributes
     * @throws \Throwable
     */
    protected function preCreateHook(array &$attributes): void
    {

    }

    /**
     * @param Model $entity
     * @return void
     */
    protected function postCreateHook(Model $entity)
    {

    }

    /**
     * @throws Exception
     */
    public function update($entity, Request $r): JsonResponse
    {
        $model = $this->getModel(
            static::MODEL_CLASS,
            $entity,
            static::GET_MODEL_BY,
        );

        $this->entityAccessHook($model);
        $this->entityUpdateAccessHook($model);

        $newAttributes = $r->validate(
            $this->validationRules(true)
        );

        try {
            $this->preUpdateHook($newAttributes, $model);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorResponse(
                $e->getMessage(),
                [],
                400
            );
        }

        return $this->updateEntity(
            $model,
            $newAttributes,
            static::MODEL_RELATIONSHIPS,
            null,
            [$this, 'mapSingleEntity']
        );
    }

    /**
     * @param array $attributes
     * @param \Illuminate\Database\Eloquent\Model $entity
     * @throws \Throwable
     */
    protected function preUpdateHook(array &$attributes, Model $entity): void
    {

    }

    /**
     * @throws Exception
     */
    public function delete($entity): JsonResponse
    {
        $model = $this->getModel(
            static::MODEL_CLASS,
            $entity,
            static::GET_MODEL_BY,
        );

        $this->entityAccessHook($model);
        $this->entityUpdateAccessHook($model);

        return $this->deleteEntity($model);
    }


    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function export(APIEntitiesRequest $r): StreamedResponse
    {
        $this->exportRequest = $r;
        if (count(static::FILTER_FIELDS) > 0) {
            $query = $this->filterQuery(
                static::MODEL_CLASS,
                $r,
                static::FILTER_FIELDS,
            );
        } else {
            $query = (static::MODEL_CLASS)::query();
        }

        $this->applySorting($r, $query);

        $relationships = array_merge(
            static::EAGER_LOAD_RELATIONSHIPS, static::EXPORT_EAGER_LOAD_RELATIONSHIPS
        );
        $relationships = array_unique($relationships);

        if (count($relationships) > 0) {
            $query->with($relationships);
        }

        return $this->exportToXLSX($query);
    }

    abstract public function validationRules(bool $update = false): array;

    public function mapSingleEntity(Model $entity): array
    {

        if (count(static::EAGER_LOAD_RELATIONSHIPS) > 0) {
            $entity->load(static::EAGER_LOAD_RELATIONSHIPS);
        }

        return $entity->jsonSerialize();
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
                    $rules["$field.*"] = ['string', 'min:1', 'max:100'];
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
    protected function filterQuery(string $modelName, Request $r, array $fields): Builder
    {
        // TODO: Make sure its a model class
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $modelName::query();

        $inputFields = $r->validate(
            $this->filterValidationRules($fields)
        );

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

    protected function entityAccessHook(Model $entity): void
    {

    }

    protected function entityUpdateAccessHook(Model $entity): void
    {

    }
}
