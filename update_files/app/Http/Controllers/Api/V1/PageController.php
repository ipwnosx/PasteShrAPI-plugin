<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Page;

class PageController extends Controller
{

    public function show($slug)
    {
        $page = Page::where('slug', $slug)->where('active', 1)->first(['title', 'content']);

        if (empty($page)) {
            return response()->json([
                'error' => 'Page not found',
            ], 404);
        }

        $description = trim(preg_replace('/\s+/', ' ', $page->content));

        $page->description = str_limit($description, 200, '...');
        $page->content     = html_entity_decode($page->content);

        return $page;
    }

}
