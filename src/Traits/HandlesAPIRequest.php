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
}
