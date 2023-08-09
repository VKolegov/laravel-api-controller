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

    protected ModelRepository $repository;
    protected ResponseBuilder $responseBuilder;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (empty(static::MODEL_CLASS) || static::MODEL_CLASS === Model::class) {
            throw new \Exception("API Controller: MODEL_CLASS is not defined");
        }

        $this->repository = new ModelRepository(static::MODEL_CLASS);
        $this->responseBuilder = new ResponseBuilder();
    }

    public function getQuery(Request $r, array $relationships)
    {
        return $this->repository
            ->setEagerLoadingRelationShips($relationships)
            ->setAllowedFilteringFields(static::FILTER_FIELDS)
            ->getQueryFromRequest($r);
    }

    public function index(Request $r): JsonResponse
    {
        $r->validate(
            $this->entitiesRequestValidationRules()
        );

        $query = $this->getQuery($r, $this->relationshipsToEagerLoad());

        return $this->responseBuilder
            ->setMappingCallback([$this, 'mapEntity'])
            ->getEntitiesResponse($r, $query);
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

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
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
        try {

            \DB::beginTransaction();

            $createEntityResponse = $this->createEntity(
                static::MODEL_CLASS,
                $attributes,
                static::MODEL_RELATIONSHIPS,
                [$this, 'mapSingleEntity'],
                $entity,
            );

            $this->postCreateHook($entity, $createEntityResponse);

            \DB::commit();
        } catch (ValidationException $e) {

            \DB::rollBack();
            throw $e;

        } catch (Throwable $e) {

            \DB::rollBack();

            \Log::error($e->getTraceAsString());

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
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    protected function postCreateHook(Model $entity, JsonResponse &$response)
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
        try {

            \DB::beginTransaction();

            $updateEntityResponse = $this->updateEntity(
                $model,
                $newAttributes,
                static::MODEL_RELATIONSHIPS,
                null,
                [$this, 'mapSingleEntity']
            );

            $this->postUpdateHook($model, $updateEntityResponse);

            \DB::commit();
        } catch (ValidationException $e) {

            \DB::rollBack();
            throw $e;

        } catch (Throwable $e) {

            \DB::rollBack();

            \Log::error($e->getTraceAsString());

            return $this->errorResponse(
                $e->getMessage(),
                [],
                400
            );
        }

        return $updateEntityResponse;
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
     * @param Model $entity
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    protected function postUpdateHook(Model $entity, JsonResponse &$response)
    {
    }


    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function export(Request $r): StreamedResponse
    {
        $r->validate(
            $this->entitiesRequestValidationRules()
        );

        $this->exportRequest = $r;

        return $this->exportToXLSX(
            $this->getQuery($r, $this->relationshipsToEagerLoadForExport())
        );
    }

    public function entitiesRequestValidationRules(): array
    {
        return [
            'onlyCount' => ['sometimes', 'boolean'],
            'sortBy' => ['sometimes', 'string'],
            'descending' => ['required_with:sortBy', 'boolean'],
            'page' => ['sometimes', 'int', 'min:1'],
            'itemsByPage' => ['sometimes', 'int', 'min:4'],
            'excludeIds' => ['sometimes', 'array'],
        ];
    }

    abstract public function validationRules(bool $update = false): array;

    /**
     * Выполняет маппинг каждой сущности (index)
     * @param \Illuminate\Database\Eloquent\Model $entity
     * @return array
     */
    public function mapEntity(Model $entity): array
    {
        return $entity->jsonSerialize();
    }

    /**
     * Выполняет маппинг единичной сущности (create, update, delete)
     * @param \Illuminate\Database\Eloquent\Model $entity
     * @return array
     */
    public function mapSingleEntity(Model $entity): array
    {

        $relationships = $this->relationshipsToEagerLoad();

        if (count($relationships) > 0) {
            $entity->load($relationships);
        }

        return $entity->jsonSerialize();
    }

    protected function entityAccessHook(Model $entity): void
    {

    }

    protected function entityUpdateAccessHook(Model $entity): void
    {

    }


    public function relationshipsToEagerLoad(): array
    {
        return static::EAGER_LOAD_RELATIONSHIPS;
    }

    public function relationshipsToEagerLoadForExport(): array
    {
        return static::EXPORT_EAGER_LOAD_RELATIONSHIPS;
    }
}
