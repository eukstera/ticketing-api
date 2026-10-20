<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('users.department_id', $request->user()->department_id)
            ->join('designations', 'designations.id', 'users.designation_id')
            ->select('users.id', 'users.department_id', 'first_name', 'last_name', 'designation', 'email')
            ->get();

        return response()->json([
            'message' => 'User retreived successfully',
            'result' => [
                'users' => $users
            ]
        ], 201);
    }

    public function getDesignations(Request $request)
    {
        $designations = Designation::where('department_id', $request->department_id)->get();

        return response()->json([
            'message' => 'Designations retreived successfully',
            'result' => [
                'designations' => $designations
            ]
        ], 201);
    }

    public function storeDesignation(Request $request)
    {
        Validator::extend('duplicate', function ($attribute, $value, $parameters, $validator) {
            $departmentId = $parameters[0] ?? null;
            return !Designation::where('department_id', $departmentId)
                ->whereRaw('LOWER(designation) = ?', [strtolower(trim($value))])
                ->exists();
        });

        Validator::replacer('duplicate', function ($message, $attribute, $rule, $parameters) {
            return 'That designation already exists in this department.';
        });

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer|exists:departments,id',
            'designation' => "required|string|max:100|duplicate:{$request->department_id}",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 200);
        }

        $designation = Designation::create([
            'department_id' => $request->department_id,
            'designation' => $request->designation
        ]);

        return response()->json([
            'result' => [
                'designation' => $designation,
            ],
        ], 201);
    }

}
