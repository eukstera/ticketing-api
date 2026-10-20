<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private AuthService $authService) {}

    public function register(Request $request)
    {
        $msg = [
            'department_id.exists' => 'The department field is required.',
            'role_id.exists' => 'The role field is required.'
        ];

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|exists:departments,id',
            'role_id' => 'required|exists:roles,id',
            'first_name' => 'required',
            'last_name' => 'required',
            'designation_id' => 'required|exists:designations,id',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8'
        ], $msg);

        if ($validator->fails()) {
            return $this->error('Please check the highlighted fields below.', 200, $validator->errors());
        }

        $result = $this->authService->register($validator->validated());

        return $this->success(
            'User created successfully', 
            ['result' => $result], 
            201);
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if($validator->fails()){
            return $this->error('Credentials not found.', 200, $validator->errors());
        }

        $result = $this->authService->login($request->only('email', 'password'));

        if($result){
            return $this->success(
                'User logged-in successfully', 
                ['result' => $result], 
                201);
        }
        
        return $this->error('Credentials not found', 401);

    }

}
