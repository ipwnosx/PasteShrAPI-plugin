<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

Route::group([
    'as'        => 'api',
    'namespace' => 'Api\V1',
], function () {

    Route::post('login', 'AuthController@login')->name('login');

    Route::get('trending', 'PasteController@trending')->name('trending');

    Route::get('search', 'PasteController@search')->name('search');
    Route::post('paste/create', 'PasteController@store')->name('paste.store');

    Route::get('archive', 'PasteController@archiveList')->name('archive.list');
    Route::get('archive/{slug}', 'PasteController@archive')->name('archive');

    Route::get('pastes', 'PasteController@index')->name('pastes.index');
    Route::post('pastes/{slug}', 'PasteController@show')->name('paste.show');

    Route::get('raw/{slug}', 'PasteController@raw')->name('raw');

    Route::get('pages/{slug}', 'PageController@show')->name('page.show');

    Route::get('u/{username}', 'UserController@show')->name('user.show');

    Route::group([
        'middleware' => 'auth:api',
    ], function () {

        Route::post('logout', 'AuthController@logout')->name('logout');

        Route::post('paste/create', 'PasteController@store')->name('paste.create');
        Route::post('paste/update', 'PasteController@update')->name('paste.edit');
        Route::post('paste/delete', 'PasteController@destroy')->name('paste.delete');

        Route::post('report-issue', 'PasteController@report')->name('paste.report');

        Route::get('my-pastes', 'UserController@pastes')->name('user.pastes');

        Route::get('profile', 'UserController@profile')->name('user.profile');

    });

});
