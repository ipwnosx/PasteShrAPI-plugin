<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Paste;
use App\User;
use Illuminate\Http\Request;
use Validator;

class UserController extends Controller
{

    public function show($username)
    {
        $user = User::where('name', $username)->first(['name', 'avatar', 'about', 'gp', 'fb', 'tw']);

        if (empty($user)) {
            return response()->json([
                'error' => 'User not found',
            ], 404);
        }

        $user_data = ["name" => $user->name, "status" => $user->status, "avatar" => $user->avatar, "about" => $user->about, "gp" => $user->gp, "fb" => $user->fb, "tw" => $user->tw];

        $pastes = Paste::where('user_id', $user->id)->where('status', 1)->where(function ($query) {
            $query->orWhereNull('user_id');
            $query->orWhereHas('user', function ($user) {
                $user->whereIn('status', [0, 1]);
            });
        })->limit(20)->simplePaginate(20, ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        $data = [
            "pastes" => $pastes,
            "user"   => $user_data,
        ];

        return response()->json(['success' => $data], 200);

    }

    public function pastes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'sometimes|min:2|max:100|eco_string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 200);
        }

        $pastes = Paste::where('user_id', \Auth::guard('api')->user()->id)->orderBy('created_at', 'DESC');

        if (!empty($request->keyword)) {
            $search_term = $request->keyword;
            $pastes      = $pastes->where(function ($q) use ($search_term) {
                $q->orWhere('title', 'like', '%' . $search_term . '%');
                $q->orWhere('content', 'like', '%' . $search_term . '%');
                $q->orWhere('syntax', 'like', '%' . $search_term . '%');

            });
        }

        $pastes = $pastes->simplePaginate(config('settings.pastes_per_page'));

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        return $pastes;
    }

    public function profile()
    {
        $user = User::where('id', \Auth::guard('api')->user()->id)->first(['name', 'avatar', 'about', 'default_paste', 'gp', 'fb', 'tw']);

        if (empty($user)) {
            return response()->json([
                'error' => 'User not found',
            ], 404);
        }

        $user_data = ["name" => $user->name, "status" => $user->status, "avatar" => $user->avatar, "about" => $user->about, "default_paste" => $user->default_paste, "gp" => $user->gp, "fb" => $user->fb, "tw" => $user->tw];

        return response()->json(['success' => $user_data], 200);
    }

}
