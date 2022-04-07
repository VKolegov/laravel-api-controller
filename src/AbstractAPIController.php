<?php
/**
 * Created by vkolegov in PhpStorm
 * Date: 20/07/2021 20:14
 */

namespace VKolegov\LaravelAPIController;


use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use VKolegov\LaravelAPIController\Requests\APIEntitiesRequest;
use VKolegov\LaravelAPIController\Traits\ExportsFilteredEntities;
use VKolegov\LaravelAPIController\Traits\HandlesAPIRequest;

abstract class AbstractAPIController extends Controller
{
    use HandlesAPIRequest, ExportsFilteredEntities;

    public const MODEL_CLASS = ""; // should be Illuminate\Database\Eloquent\Model class name
    public const GET_MODEL_BY = null;
    public const MODEL_RELATIONSHIPS = [];
    public const FILTER_FIELDS = [];
    public const EAGER_LOAD_RELATIONSHIPS = [];
    public const EXPORT_EAGER_LOAD_RELATIONSHIPS = [];

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (empty(static::MODEL_CLASS) || static::MODEL_CLASS === Model::class) {
            throw new \Exception("API Controller: MODEL_CLASS is not defined");
        }
    }

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


        return $this->getEntitiesResponse(
            $query,
            $r,
        );
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

    protected function entityAccessHook(Model $entity): void
    {

    }

    protected function entityUpdateAccessHook(Model $entity): void
    {

    }
}
