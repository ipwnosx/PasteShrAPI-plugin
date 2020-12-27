<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Paste;
use App\Models\Report;
use App\Models\Syntax;
use Illuminate\Http\Request;
use Validator;

class PasteController extends Controller
{
    public function index()
    {
        $pastes = Paste::where('status', 1)->where(function ($query) {
            $query->where('expire_time', '>', \Carbon\Carbon::now())->orWhereNull('expire_time');
        })->orderBy('created_at', 'desc')->limit(50)->simplePaginate(50, ['title', 'syntax', 'slug', 'created_at', 'expire_time', 'views', 'encrypted']);

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        return $pastes;

    }
    public function show($slug, Request $request)
    {
        $paste = Paste::where('slug', $slug)->where(function ($query) {
            $query->orWhereNull('user_id');
            $query->orWhereHas('user', function ($user) {
                $user->whereIn('status', [0, 1]);
            });
        })->first();

        if (empty($paste)) {
            return response()->json([
                'error' => 'Paste not found',
            ], 404);
        }

        if ($paste->status == 3) {
            if (\Auth::guard('api')->check()) {
                if ($paste->user_id != \Auth::user()->id) {
                    return response()->json(['error' => 'You are not allowed to access this paste'], 200);
                }
            } else {
                return response()->json(['error' => 'You are not allowed to access private paste'], 200);
            }

        }

        if ($paste->self_destroy == 1 && $paste->views > config('settings.self_destroy_after_views') && empty($paste->expire_time)) {
            $paste->expire_time = date("Y-m-d H:i:s");
            $paste->save();
        }

        if (!empty($paste->expire_time)) {
            if (strtotime($paste->expire_time) < time()) {
                return response()->json(['error' => 'Paste is expired'], 200);
            }
        }

        if (!empty($paste->password)) {
            if (!password_verify($request->password, $paste->password)) {
                return response()->json(['error' => 'Invalid password'], 200);
            }
        }

        if (session()->has('already_viewed')) {
            $already_viewed = session('already_viewed');

            if (!in_array($paste->id, $already_viewed)) {
                array_push($already_viewed, $paste->id);
                $paste->views = $paste->views + 1;
                $paste->save();
            }

            session(['already_viewed' => $already_viewed]);
        } else {
            $already_viewed = [$paste->id];
            session(['already_viewed' => $already_viewed]);
            $paste->views = $paste->views + 1;
            $paste->save();
        }

        if ($paste->storage == 2) {
            $paste->content = file_get_contents(ltrim($paste->content, '/'));
        }

        if ($paste->encrypted == 1) {
            $paste->content = decrypt($paste->content);
        }

        $description = config('settings.meta_description');
        if (empty($paste->password)) {
            $description = $paste->content;
            $description = strip_tags($paste->content);
        }

        $description = trim(preg_replace('/\s+/', ' ', $description));

        $paste->description = str_limit($description, 200, '...');

        if (isset($paste->language)) {
            $extension = (!empty($paste->language->extension)) ? $paste->language->extension : 'txt';
        } else {
            $extension = 'txt';
        }

        $paste->extension = $extension;
        $paste_user       = null;

        if (!empty($paste->user)) {
            $paste_user = ["name" => $paste->user->name, "avatar" => $paste->user->avatar, "url" => $paste->user->url];
        }

        $data = ["title" => $paste->title_f, "slug" => $paste->slug, "syntax" => $paste->slug, "expire_time" => $paste->expire_time, "status" => $paste->status, "views" => $paste->views, "description" => $paste->description, "encrypted" => $paste->encrypted, "extension" => $paste->extension, "created_at" => $paste->created_at->format('Y-m-d H:i:s'), "url" => $paste->url];

        $data['content'] = html_entity_decode($paste->content);

        $data['user'] = $paste_user;

        return response()->json([
            'success' => $data,
        ]);

    }

    public function store(Request $request)
    {
        if (\Auth::guard('api')->check()) {
            if (\Auth::guard('api')->user()->role != 1) {
                if (config('settings.user_paste') != 1) {
                    return response()->json(['error' => 'User pasting is currently disabled'], 200);
                }
            }
            $allowed_status = '1,2,3';
        } else {
            if (config('settings.public_paste') != 1) {
                return response()->json(['error' => 'Public pasting is currently disabled please login to create a paste'], 200);
            }
            $allowed_status = '1,2';
        }

        $title_required = 'nullable';
        if (config('settings.paste_title_required') == 1) {
            $title_required = 'required';
        }

        $validator = Validator::make($request->all(), [
            'content'  => 'required|min:1',
            'status'   => 'required|numeric|in:' . $allowed_status,
            'syntax'   => 'nullable|exists:syntax,slug',
            'expire'   => 'nullable|max:3|in:N,10M,1H,1D,1W,2W,1M,6M,1Y,SD',
            'title'    => $title_required . '|max:80|eco_string',
            'password' => 'nullable|max:50|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 200);
        }
        $content_size = strlen($request->content) / 1000;

        if ($content_size > config('settings.max_content_size_kb')) {
            return response()->json(['error' => 'Max allowed content size is' . ' ' . config('settings.max_content_size_kb') . 'kb'], 200);
        }

        $ip_address = request()->ip();

        if (\Auth::guard('api')->check()) {
            $paste_count = Paste::where('user_id', \Auth::guard('api')->user()->id)->whereDate('created_at', date("Y-m-d"))->count();
            if ($paste_count >= config('settings.daily_paste_limit_auth')) {
                return response()->json(['error' => 'Daily paste limit reached'], 200);
            }

            $last_paste = Paste::where('user_id', \Auth::guard('api')->user()->id)->orderBy('created_at', 'DESC')->limit(1)->first();
            if (!empty($last_paste)) {

                if (strtotime($last_paste->created_at) > strtotime('-' . config('settings.paste_time_restrict_auth') . ' seconds')) {
                    $mins = config('settings.paste_time_restrict_auth') / 60;

                    return response()->json(['error' => 'Please wait' . ', ' . $mins . ' ' . 'minutes before making another paste'], 200);
                }
            }
        } else {
            $paste_count = Paste::where('ip_address', $ip_address)->whereDate('created_at', date("Y-m-d"))->count();
            if ($paste_count >= config('settings.daily_paste_limit_unauth')) {

                return response()->json(['error' => 'Daily paste limit reached, Please login to increase your paste limit'], 200);
            }

            $last_paste = Paste::where('ip_address', $ip_address)->orderBy('created_at', 'DESC')->limit(1)->first();
            if (!empty($last_paste)) {

                if (strtotime($last_paste->created_at) > strtotime('-' . config('settings.paste_time_restrict_unauth') . ' seconds')) {
                    $mins = config('settings.paste_time_restrict_unauth') / 60;

                    return response()->json(['error' => 'Please wait' . ', ' . $mins . ' ' . 'minutes before making another paste'], 200);
                }
            }
        }

        $paste         = new Paste();
        $paste->title  = $request->title;
        $paste->slug   = str_random(10);
        $paste->syntax = (!empty($request->syntax)) ? $request->syntax : config('settings.default_syntax');

        switch ($request->expire) {
            case '10M':
                $expire = '10 minutes';
                break;

            case '1H':
                $expire = '1 hour';
                break;

            case '1D':
                $expire = '1 day';
                break;

            case '1W':
                $expire = '1 week';
                break;

            case '2W':
                $expire = '2 week';
                break;

            case '1M':
                $expire = '1 month';
                break;

            case '6M':
                $expire = '6 months';
                break;

            case '1Y':
                $expire = '1 year';
                break;

            case 'SD':
                $expire = 'SD';
                break;

            default:
                $expire = 'N';
                break;
        }

        if ($expire != 'N') {
            if ($expire == 'SD') {
                $paste->self_destroy = 1;
            } else {
                $paste->expire_time = date('Y-m-d H:i:s', strtotime('+' . $expire));
            }

        }

        $paste->status = $request->status;

        if (\Auth::guard('api')->check()) {
            $paste->user_id = \Auth::guard('api')->user()->id;
        }
        $paste->ip_address = $ip_address;

        if ($request->password) {
            $paste->password = \Hash::make($request->password);
        }

        if ($request->encrypted) {
            $paste->encrypted = 1;
            $paste->content   = encrypt($request->content);

        } else {
            $paste->content = htmlentities($request->content);
        }

        if (config('settings.paste_storage') == 'file') {
            $paste->storage = 2;
            $content        = $paste->content;
            if (\Auth::guard('api')->check()) {
                $destination_path = 'uploads/users/' . \Auth::guard('api')->user()->name;
            } else {
                $destination_path = 'uploads/pastes/' . date('Y') . '/' . date('m') . '/' . date('d');
            }

            if (!file_exists($destination_path)) {
                mkdir($destination_path, 0775, true);
            }
            $filename = md5($paste->slug) . '.txt';
            file_put_contents($destination_path . '/' . $filename, $content);
            $paste->content = '/' . $destination_path . '/' . $filename;
        }

        $paste->save();

        $data = [
            "messages"  => 'Paste successfully created',
            "slug"      => $paste->slug,
            "paste_url" => $paste->url,
        ];

        return response()->json(['success' => $data], 200);
    }

    public function search(Request $request)
    {
        if (config('settings.search_page') != 1) {
            return response()->json(['error' => 'This feature is disabled'], 200);
        }

        $validator = Validator::make($request->all(), [
            'keyword' => 'required|min:2|max:100|eco_string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 200);
        }

        $search_term = $request->keyword;

        $pastes = Paste::where(function ($q) use ($search_term) {
            $q->orWhere('title', 'like', '%' . $search_term . '%');
            $q->orWhere('content', 'like', '%' . $search_term . '%');
            $q->orWhere('syntax', 'like', '%' . $search_term . '%');

        })->where(function ($query) {
            $query->where('expire_time', '>', \Carbon\Carbon::now())->orWhereNull('expire_time');
        })->where('status', 1)->where(function ($query) {
            $query->orWhereNull('user_id');
            $query->orWhereHas('user', function ($user) {
                $user->whereIn('status', [0, 1]);
            });
        })->orderBy('created_at', 'desc');

        if (\Auth::guard('api')->check()) {
            $pastes = $pastes->simplePaginate(config('settings.pastes_per_page'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } else {
            $pastes = $pastes->limit(config('settings.pastes_per_page'))->simplePaginate(config('settings.pastes_per_page'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        }

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        return $pastes;
    }

    public function archive($slug)
    {
        if (config('settings.archive_page') != 1) {
            return response()->json(['error' => 'This feature is disabled'], 200);
        }

        $syntax = Syntax::where('slug', $slug)->first(['name', 'slug']);

        if (empty($syntax)) {
            return response()->json([
                'error' => 'Archive not found',
            ], 404);
        }

        $pastes = Paste::where('syntax', $slug)->where(function ($query) {
            $query->where('expire_time', '>', \Carbon\Carbon::now())->orWhereNull('expire_time');
        })->where('status', 1)->where(function ($query) {
            $query->orWhereNull('user_id');
            $query->orWhereHas('user', function ($user) {
                $user->whereIn('status', [0, 1]);
            });
        })->orderBy('created_at', 'DESC');

        if (\Auth::guard('api')->check()) {
            $pastes = $pastes->simplePaginate(config('settings.pastes_per_page'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } else {
            $pastes = $pastes->limit(config('settings.pastes_per_page'))->simplePaginate(config('settings.pastes_per_page'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        }

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        return $pastes;
    }

    public function archiveList()
    {
        if (config('settings.archive_page') != 1) {
            return response()->json(['error' => 'This feature is disabled'], 200);
        }

        $syntaxes = Syntax::where('active', 1)->orderby('name')->get(['name', 'slug']);

        foreach ($syntaxes as $syntax) {
            $syntax->url = $syntax->url;
        }

        return $syntaxes;
    }

    public function update(Request $request)
    {
        $paste = Paste::where('slug', $request->slug)->where('user_id', \Auth::guard('api')->user()->id)->first();

        if (empty($paste)) {
            return response()->json([
                'error' => 'Paste not found',
            ], 404);
        }

        if (!empty($paste->expire_time)) {
            if (strtotime($paste->expire_time) < time()) {
                return response()->json(['error' => 'Paste is expired'], 200);
            }
        }
        $title_required = 'nullable';
        if (config('settings.paste_title_required') == 1) {
            $title_required = 'required';
        }

        $validator = Validator::make($request->all(), [
            'content'  => 'required|min:1',
            'status'   => 'sometimes|numeric|in:1,2,3',
            'syntax'   => 'nullable|exists:syntax,slug',
            'title'    => $title_required . '|max:80|eco_string',
            'password' => 'nullable|max:50|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 200);
        }

        $content_size = strlen($request->content) / 1000;

        if ($content_size > config('settings.max_content_size_kb')) {
            return response()->json(['error' => 'Max allowed content size is' . ' ' . config('settings.max_content_size_kb') . 'kb'], 200);
        }

        $paste->title  = $request->title;
        $paste->syntax = (!empty($request->syntax)) ? $request->syntax : config('settings.default_syntax');
        if (!empty($request->status)) {
            $paste->status = $request->status;
        }

        if ($request->password) {
            $paste->password = \Hash::make($request->password);
        }

        if ($request->encrypted) {
            $paste->encrypted = 1;
            $paste->content   = encrypt($request->content);

        } else {
            $paste->encrypted = 0;
            $paste->content   = htmlentities($request->content);
        }

        if (config('settings.paste_storage') == 'file') {

            if ($paste->storage == 2) {
                if (file_exists(ltrim($paste->content, '/'))) {
                    unlink(ltrim($paste->content, '/'));
                }
            }

            $paste->storage = 2;
            $content        = $paste->content;
            if (\Auth::guard('api')->check()) {
                $destination_path = 'uploads/users/' . \Auth::guard('api')->user()->name;
            } else {
                $destination_path = 'uploads/pastes/' . date('Y') . '/' . date('m') . '/' . date('d');
            }

            if (!file_exists($destination_path)) {
                mkdir($destination_path, 0775, true);
            }
            $filename = md5($paste->slug) . '.txt';
            file_put_contents($destination_path . '/' . $filename, $content);
            $paste->content = '/' . $destination_path . '/' . $filename;
        }

        $paste->save();

        $data = [
            "messages"  => 'Paste successfully updated',
            "slug"      => $paste->slug,
            "paste_url" => $paste->url,
        ];

        return response()->json(['success' => $data], 200);

    }

    public function destroy($slug)
    {
        $paste = Paste::where('slug', $slug)->where('user_id', \Auth::guard('api')->user()->id)->first();

        if (empty($paste)) {
            return response()->json([
                'error' => 'Paste not found',
            ], 404);
        }

        if ($paste->storage == 2) {
            if (file_exists(ltrim($paste->content, '/'))) {
                unlink(ltrim($paste->content, '/'));
            }
        }
        $paste->delete();
        return response()->json(['success' => 'Paste successfully deleted'], 200);
    }

    public function report(Request $request)
    {
        if (config('settings.feature_report') != 1) {
            return response()->json(['error' => 'This feature is disabled'], 200);
        }

        $validator = Validator::make($request->all(), [
            'slug'   => 'required|alpha_num|exists:pastes,slug',
            'reason' => 'required|eco_long_string|min:10|max:1000',

        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 200);
        }

        $paste = Paste::where('slug', $request->slug)->first();

        if (empty($paste)) {
            return response()->json([
                'error' => 'Paste not found',
            ], 404);
        }

        $report           = new Report();
        $report->paste_id = $paste->id;
        $report->user_id  = \Auth::guard('api')->user()->id;
        $report->reason   = $request->reason;
        $report->save();

        return response()->json(['success' => 'Paste successfully reported'], 200);
    }

    public function trending(Request $request)
    {
        if (config('settings.trending_page') != 1) {
            return response()->json(['error' => 'This feature is disabled'], 200);
        }

        if (empty($request->t)) {
            return response()->json(['error' => 'Bad request'], 200);
        }

        if ($request->t == 'today') {
            $pastes = Paste::where('status', 1)->where(function ($query) {
                $query->orWhereNull('user_id');
                $query->orWhereHas('user', function ($user) {
                    $user->whereIn('status', [0, 1]);
                });
            })->whereDate('created_at', date('Y-m-d'))->orderby('views', 'DESC')->limit(config('settings.trending_pastes_limit'))->simplePaginate(config('settings.trending_pastes_limit'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } elseif ($request->t = 'week') {
            $pastes = Paste::where('status', 1)->where(function ($query) {
                $query->orWhereNull('user_id');
                $query->orWhereHas('user', function ($user) {
                    $user->whereIn('status', [0, 1]);
                });
            })->whereBetween('created_at', [
                \Carbon\Carbon::parse('last monday')->startOfDay(),
                \Carbon\Carbon::parse('next sunday')->endOfDay(),
            ])->orderby('views', 'DESC')->limit(config('settings.trending_pastes_limit'))->simplePaginate(config('settings.trending_pastes_limit'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } elseif ($request->t == 'month') {

            $pastes = Paste::where('status', 1)->where(function ($query) {
                $query->orWhereNull('user_id');
                $query->orWhereHas('user', function ($user) {
                    $user->whereIn('status', [0, 1]);
                });
            })->whereBetween('created_at', [
                \Carbon\Carbon::now()->startOfMonth(),
                \Carbon\Carbon::now()->endOfMonth(),
            ])->orderby('views', 'DESC')->limit(config('settings.trending_pastes_limit'))->simplePaginate(config('settings.trending_pastes_limit'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } elseif ($request->t == 'year') {

            $pastes = Paste::where('status', 1)->where(function ($query) {
                $query->orWhereNull('user_id');
                $query->orWhereHas('user', function ($user) {
                    $user->whereIn('status', [0, 1]);
                });
            })->whereBetween('created_at', [
                \Carbon\Carbon::now()->startOfYear(),
                \Carbon\Carbon::now()->endOfYear(),
            ])->orderby('views', 'DESC')->limit(config('settings.trending_pastes_limit'))->simplePaginate(config('settings.trending_pastes_limit'), ['title', 'syntax', 'slug', 'created_at', 'password', 'expire_time', 'views']);
        } else {
            return response()->json(['error' => 'Bad request'], 200);
        }

        foreach ($pastes as $paste) {
            $paste->password_protected = (!empty($paste->password)) ? true : false;
            $paste->url                = $paste->url;
        }

        return $pastes;
    }
}
