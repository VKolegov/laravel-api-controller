<?php

namespace VKolegov\LaravelAPIController;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Helpers\EloquentBuilder;

class EntitiesRequestQueryBuilder
{
    private Request $request;
    /**
     * @var Builder|Relation
     */
    private $query;

    /**
     * @param Request $request
     * @param Builder|Relation $query
     */
    public function __construct(Request $request, $query)
    {
        $this->request = $request;
        $this->query = $query;
    }

    public function excludeIds(): self
    {
        // Исключаем айдишники
        $excludeIds = $this->request->get('excludeIds');

        if (is_array($excludeIds)) {
            $table = $this->query->getModel()->getTable();
            $key = $this->query->getModel()->getKeyName();
            $column = "$table.$key"; // e.g. products.id
            $this->query->whereNotIn($column, $excludeIds);
        }

        return $this;
    }

    /**
     * @param string[] $fields key - field to filter by, value = field type
     * @return self
     * @throws Exception
     */
    public function applyFiltering(array $fields): self
    {

        if (empty($fields)) {
            return $this;
        }

        // To support filtering by embedded mongodb fields, like 'store.id'
        if ($this->query->getModel()->getConnection()->getDriverName() !== 'mongodb') {
            $inputFields = $this->request->validate(
                $this->filteringValidationRules($fields)
            );
        } else {
            $inputFields = $this->request->all();
        }

        if (empty($inputFields)) {
            return $this;
        }

        foreach ($fields as $field => $type) {

            $fieldQ = $inputFields[$field] ?? "";

            switch ($type) {
                case 'bool':

                    $fieldQ = $inputFields[$field] ?? null;
                    if ($fieldQ === null) break;

                    $this->query->where($field, $fieldQ);
                    break;
                case 'string':

                    if (!$fieldQ) break;

                    $this->query->where($field, 'like', "%$fieldQ%");
                    break;
                case 'select':

                    if (!is_array($fieldQ)) break;

                    $this->query->where(function ($q) use ($field, $fieldQ) {

                        foreach ($fieldQ as $selectValue) {
                            $q->orWhere($field, '=', $selectValue);
                        }

                    });

                    break;

                case 'num_range':

                    $fieldMin = $inputFields["{$field}_min"] ?? null;
                    $fieldMax = $inputFields["{$field}_max"] ?? null;

                    if (!is_null($fieldMin)) {
                        $this->query->where($field, '>=', $fieldMin);
                    }

                    if (!is_null($fieldMax)) {
                        $this->query->where($field, '<=', $fieldMax);
                    }
                    break;

                case 'date_range':

                    $fieldMin = $inputFields["{$field}_min"] ?? null;
                    $fieldMax = $inputFields["{$field}_max"] ?? null;

                    if (!is_null($fieldMin)) {
                        $minDate = Carbon::parse($fieldMin)->startOfDay();
                        $this->query->where($field, '>=', $minDate);
                    }

                    if (!is_null($fieldMax)) {
                        $maxDate = Carbon::parse($fieldMax)->endOfDay();
                        $this->query->where($field, '<=', $maxDate);
                    }

                    break;
            }

        }

        return $this;

    }

    protected function filteringValidationRules(array $fields): array
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
     * @param string|null $fieldCase
     * @return self
     */
    public function applySorting(?string $fieldCase = null): self
    {
        $sortBy = $this->request->get('sortBy');

        if ($sortBy) {
            $sortOrder = $this->request->get('descending', true)
                ? 'desc'
                : 'asc';

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

            $this->query->orderBy($sortBy, $sortOrder);
        }

        return $this;
    }

    /**
     * @return Builder|Relation
     */
    public function getQuery()
    {
        return $this->query;
    }
}