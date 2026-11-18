<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Teacher;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassroomController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Classroom::query()->withCount('students')->with('homeroomTeacher:id,name')->orderBy('name')->get()
        );
    }

    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        return response()->json(Classroom::create($this->validated($request, $tenant)), 201);
    }

    public function update(Request $request, TenantContext $tenant, Classroom $classroom): JsonResponse
    {
        $classroom->update($this->validated($request, $tenant, $classroom));

        return response()->json($classroom);
    }

    public function destroy(Classroom $classroom): JsonResponse
    {
        $classroom->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, TenantContext $tenant, ?Classroom $c = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64',
                Rule::unique('classrooms')->where('school_id', $tenant->id())->ignore($c?->id)],
            'grade' => ['nullable', 'string', 'max:8'],
            'homeroom_teacher_id' => ['nullable', 'integer'],
        ]);

        // Wali kelas harus guru dari sekolah yang sama (Teacher::find sudah ter-scope).
        if (! empty($data['homeroom_teacher_id']) && ! Teacher::find($data['homeroom_teacher_id'])) {
            abort(422, 'Wali kelas tidak ditemukan di sekolah ini.');
        }

        return $data;
    }
}
