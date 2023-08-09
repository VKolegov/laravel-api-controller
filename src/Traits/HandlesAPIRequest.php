<?php
/**
 * Created by vkolegov in PhpStorm
 * Date: 05/07/2020 12:50
 */

namespace VKolegov\LaravelAPIController\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Log;

trait HandlesAPIRequest
{

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
                throw new Exception("Entity should exist");
            }

            $entity->fill(
                $this->getPureAttributes($newAttributes, $newRelationships)
            );

            if (!$entity->save()) {
                return $this->errorResponse(
                    "Не удалось обновить $modelName #$id"
                );
            }

            if (!empty($newRelationships)) {
                if (!is_array($newRelationships) || !is_array($newRelationships[0])) {
                    throw new InvalidArgumentException("\$newRelationShips should be 2D array");
                }
                $this->updateRelationships($entity, $newAttributes, $newRelationships);
            }

            return $this->successfulEntityModificationResponse(
                $entity,
                $mappingCallback
            );

        } catch (Exception $e) {
            Log::error($e);
            return $this->errorResponse(
                "Не удалось обновить $modelName #$id",
                [$e->getMessage()]
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
     * @throws Exception
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
                    'deletedEntity' => $entity,
                ]);
            } else {
                return $this->errorResponse(
                    "Не удалось удалить $modelName #$id"
                );
            }
        } catch (Exception $e) {
            Log::error($e);

            return $this->errorResponse(
                "Ошибка при удалении $modelName #$id: " . $e->getMessage()
            );
        }
    }

    // TODO: Tests

    /**
     * @param Model|string $entityModel Model entity or model class
     * @param int|string|null $id Model ID
     * @param string|null $getByField
     * @return Model
     * @throws Exception
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
                $entity = $entityModel::query()->findOrFail($id);
            } else {
                $entity = $entityModel::query()->where($getByField, $id)->first();

                if (!$entity) {
                    throw (new ModelNotFoundException)->setModel(
                        $entityModel, $id
                    );
                }
            }
        } else {
            throw new Exception("Entity model should be an instance of Model or a FQN");
        }

        return $entity;
    }

    /**
     * @param string $comment
     * @param array $errorMessages
     * @param int $code
     * @return JsonResponse
     * @deprecated
     */
    protected function errorResponse(string $comment, array $errorMessages = [], int $code = 500): JsonResponse
    {
        return new JsonResponse(
            [
                'success' => false,
                'comment' => $comment,
                'errors' => $errorMessages,
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
                'entity' => $mappedEntity,
            ], $code
        );
    }
}
