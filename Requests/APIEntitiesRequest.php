<?php

namespace VKolegov\LaravelAPIController\Requests;

use Illuminate\Foundation\Http\FormRequest;

class APIEntitiesRequest extends FormRequest
{

    protected $stopOnFirstFailure = true;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'onlyCount' => ['sometimes', 'boolean'],
            'sortBy' => ['sometimes', 'string'],
            'descending' => ['required_with:sortBy', 'boolean'],
            'page' => ['sometimes', 'int', 'min:1'],
            'itemsByPage' => ['sometimes', 'int', 'min:4'],
            'excludeIds' => ['sometimes', 'array'],
            'q' => ['sometimes', 'string', 'min:3', 'max:60'],
            'searchBy' => ['required_with:q', 'string']
        ];
    }
}
