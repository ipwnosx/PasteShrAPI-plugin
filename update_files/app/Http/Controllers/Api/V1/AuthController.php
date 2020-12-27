<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\User;
//use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Auth;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    //  use AuthenticatesUsers;

    /**
     * Validate the user login request.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateLogin(Request $request)
    {

        if (strpos($request->username, '@')) {
            $rules = [
                'username' => 'required|max:150|email',
                'password' => 'required|string|max:20',
            ];
        } else {

            $rules = [
                'username' => 'required|alpha_num|max:100',
                'password' => 'required|string|max:20',
            ];
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->messages(), 200);
        }

    }

    /**
     * Handle an authentication attempt.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return Response
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);
        $remember = false;

        if (Auth::attempt(["name" => $request->username, "password" => $request->password], $remember)) {
            if (Auth::guard()->user()->status == 2) {
                Auth::logout();

                return response()->json(['error' => 'Your account is banned'], 200);
            }

            // Authentication passed...
            $this->generateToken();
            $user = Auth::guard()->user();

            $data = ["message" => __('You successfully logged in'), "api_token" => $user->api_token, "user" => ["name" => $user->name, "email" => $user->email, "avatar" => $user->avatar, "about" => $user->about, "default_paste" => $user->default_paste, "gp" => $user->gp, "fb" => $user->fb, "tw" => $user->tw]];

            return response()->json([
                'success' => $data,
            ]);

        } elseif (Auth::attempt(["email" => $request->username, "password" => $request->password], $remember)) {
            if (\Auth::user()->status == 2) {
                \Auth::logout();

                return response()->json(['error' => 'Your account is banned'], 200);
            }

            // Authentication passed...
            $this->generateToken();
            $user = Auth::guard()->user();

            $data = ["message" => __('You successfully logged in'), "api_token" => $user->api_token, "user" => ["name" => $user->name, "email" => $user->email, "avatar" => $user->avatar, "about" => $user->about, "default_paste" => $user->default_paste, "gp" => $user->gp, "fb" => $user->fb, "tw" => $user->tw]];

            return response()->json([
                'success' => $data,
            ]);

        } else {
            return response()->json(['error' => 'Invalid username or password'], 200);
        }
    }

    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user();

        if ($user) {
            $user->api_token = null;
            $user->save();
        }

        return response()->json(['success' => 'You successfully logged out'], 200);
    }

    private static function generateToken()
    {
        $user = Auth::guard()->user();
        if (!empty($user)) {

            $token           = str_random(60);
            $user->api_token = hash('sha256', $token);
            $user->save();

            return $token;
        }
        return false;
    }
}
