<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CatalogController extends Controller
{
    abstract protected function model(): Model;

    abstract protected function rules(): array;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']], ['integer' => 'El campo :attribute debe ser entero.', 'min' => 'El campo :attribute está por debajo del límite permitido.', 'max' => 'El campo :attribute supera el límite permitido.']);

        return response()->json($this->model()->newQuery()->orderBy('id')->paginate($data['per_page'] ?? 25))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.', 'boolean' => 'El campo :attribute debe ser verdadero o falso.', 'max' => 'El campo :attribute supera el límite permitido.']);
        $model = $this->model();
        $model->fill($data)->save();

        return response()->json(['data' => $model], 201)->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->model()->newQuery()->findOrFail($id)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(), ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.', 'boolean' => 'El campo :attribute debe ser verdadero o falso.', 'max' => 'El campo :attribute supera el límite permitido.']);
        $model = $this->model()->newQuery()->findOrFail($id);
        $model->fill($data)->save();

        return response()->json(['data' => $model])->header('Cache-Control', 'no-store');
    }
}
