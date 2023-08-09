<?php

namespace VKolegov\LaravelAPIController;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ResponseBuilder
{
    /**
     * @var callable|null
     */
    private $mappingCallback = null;
    private int $maxEntities = 1000;

    /**
     * @param Request $r
     * @param Builder|Relation $query
     * @return JsonResponse
     */
    public function getEntitiesResponse(Request $r, $query): JsonResponse
    {
        // Сначала считаем что вообще есть в базе
        // Подсчёт через getCountForPagination() считает сколько всего записей попало в фильтр
        // Без учета разбиения на страницы ( e.g. take() и offset())
        $count = $query->count();

        // Если по нулям - дальше даже не утруждаемся
        if ($count === 0) {
            return new JsonResponse(
                $this->getEntitiesResponseArray(collect(), 0)
            );
        }

        $onlyCount = false;

        if ($onlyCountRaw = $r->get('onlyCount')) {
            $onlyCount = filter_var($onlyCountRaw, FILTER_VALIDATE_BOOL);
        }

        // Если от нас требуется только подсчет
        if ($onlyCount) {
            return new JsonResponse(
                $this->getEntitiesResponseArray(collect(), $count)
            );
        }

        // Пагинация
        $entities = $this->paginatedQuery($r, $query)->get();

        return new JsonResponse(
            $this->getEntitiesResponseArray($entities, $count)
        );
    }

    public function getEntitiesResponseArray(
        iterable $entities,
        int      $count = 0
    ): array
    {

        // Если задан метод, который выполняет маппинг сущности
        if (is_callable($this->mappingCallback)) {
            if ($entities instanceof Collection) {
                $entities = $entities->map($this->mappingCallback);
            }
            if (is_array($entities)) {
                $entities = array_map($this->mappingCallback, $entities);
            }
        }

        return [
            'count' => $count ?? count($entities),
            'entities' => $entities,
        ];
    }


    /**
     * @param callable|null $mappingCallback
     * @return ResponseBuilder
     */
    public function setMappingCallback(?callable $mappingCallback): ResponseBuilder
    {
        $this->mappingCallback = $mappingCallback;
        return $this;
    }

    /**
     * @param int $maxEntities
     * @return ResponseBuilder
     */
    public function setMaxEntities(int $maxEntities): ResponseBuilder
    {
        $this->maxEntities = $maxEntities;
        return $this;
    }

    /**
     * @param Request $request
     * @param Relation|Builder|EloquentBuilder $query
     * @return Relation|\Illuminate\Database\Eloquent\Builder|EloquentBuilder
     */
    public function paginatedQuery(Request $request, $query)
    {

        $itemsByPage = intval(
            $request->get('itemsByPage', 20)
        );

        $itemsByPage = min($itemsByPage, $this->maxEntities); // Жестко ограничиваем тысячей

        $page = intval(
            $request->get('page', 1)
        );

        $offset = ($page - 1) * $itemsByPage;

        return $query->take($itemsByPage)->skip($offset);
    }
}