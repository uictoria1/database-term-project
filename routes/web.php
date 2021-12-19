<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

// $router->get('/', function () use ($router) {
//     $results = app('db')->select("SELECT user,host FROM mysql.user;");
//     return $results;
// });

$router->get('/reset_db', 'UserController@reset_db');

// authentication
$router->post("/register", "UserController@register");
$router->post('/login', 'UserController@login');
$router->post('/logout', 'UserController@logout');

// user
$router->get("/roles", "RoleController@roles");
$router->get("/users", "UserController@users");
$router->get("/users/{user_id}", "UserController@user_by_id");
$router->get("/role_user", "UserController@role_user");
$router->post('restrict-user', "UserController@restrict_user");
$router->post('activate-user', "UserController@activate_user");

// ######
// books

// create 
$router->post('/books', 'BookController@create_book');
// read
$router->get("/books/all", "BookController@books");
$router->get("/books/digital", "BookController@books_digital");
$router->get("/books/physical", "BookController@books_physical");
$router->get('/books/all/{id}', ['as' => 'book.all', 'uses' => 'BookController@read_book_all']);
$router->get('/books/physical/{id}', 'BookController@read_book_physical');
$router->get('/books/digital/{id}', 'BookController@read_book_digital');
// update
$router->put('/books', 'BookController@update_book');
// delete
$router->delete('/books/{id}', 'BookController@delete_book');
// link category
$router->post("/books/link/category", "BookController@link_category");
$router->post("/books/unlink/category", "BookController@unlink_category");
$router->post("/books/link/tag", "BookController@link_tag");
$router->post("/books/unlink/tag", "BookController@link_tag");
$router->get("/books/search", "BookController@search");

// ########
// booking details

// create
$router->post("/booking-details", "BookingDetailsController@create");

// read
$router->get("/booking-details", "BookingDetailsController@get");

// update
$router->put("/booking-details/cancel", "BookingDetailsController@cancel");
$router->put("/booking-details/return", "BookingDetailsController@return");

// ######
// categories

// create
$router->post('/categories', "CategoryController@create");

// get
$router->get("/categories", "CategoryController@getAll");
$router->get("/categories/{id}", "CategoryController@getById");

// update
$router->put("/categories", "CategoryController@update");

// delete
$router->delete("/categories/{id}", "CategoryController@delete");


$router->get("/download/{id}", 'BookController@download_by_id');

// #####
// wishlists
$router->post("/wishlist", "WishlistController@link");
$router->delete("/wishlist", "WishlistController@unlink");
$router->get("/wishlist", "WishlistController@get");


// ####
// tags
$router->post("/tags", "TagController@create");
$router->get("/tags", "TagController@get_all");
$router->get("/tags/{id}", "TagController@get_by_id");
$router->put("/tags", "TagController@update");
$router->delete("/tags/{id}", "TagController@delete");