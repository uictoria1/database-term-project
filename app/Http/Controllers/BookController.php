<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth'); 
    }

    // create 
    public function create_book(Request $request){
        $validator = Validator::make($request->all(), [
            'type' => 'required',
            'title' => 'required',
            'isbn' => 'required',
            'author' => 'required',
            'description' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $book_keys = [
            "type",
            "title",
            "isbn",
            "author",
            "description",
        ];

        $physical_keys = [
            "copy_number"
        ];

        $digital_keys = [
            "download_url"
        ];

        try {
            $conn = app(\PDO::class);
            $create_stmt = $conn->prepare("insert into books(`type`, title, isbn, author, description) values(?, ?, ?, ?, ?)");
            $array = array_intersect_key($request->all(), array_flip($book_keys));
            $create_stmt->execute(array_values($array));
            $book_id = $conn->lastInsertId();

            if($book_id){
                $type = $request->input("type");
                if($type == "physical"){
                    $phys_stmt = $conn->prepare("insert into physical_books(id, copy_number) values(?, ?);");
                    $phys_stmt->execute(array($book_id, $request->input("copy_number")));
                }
                else if($type == "digital"){
                    $digital_stmt = $conn->prepare("insert into digital_books(id, download_url) values(?, ?);");
                    $digital_stmt->execute(array($book_id, $request->input("download_url")));
                }
                return redirect()->route('book.all', [
                    'id' => $book_id,
                ]);
            }
            else{
                return response()->json([
                    "status" => false,
                    "errors" => [
                        "message" => "Something is happened at our end",
                    ]
                ], 500);
            }
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    $th->getMessage()
                ]
            ]);
        }
    }

    // get requests all
    public function books(Request $request){
        $conn = app(\PDO::class);
        $user = $request->user();

        $user_id = $user->id;

        $books_physical = $this->get_physical_books($user_id);
        $books_digital = $this->get_digital_books($user_id);
        $books = array_merge($books_physical, $books_digital);

        for ($i=0; $i < count($books); $i++) { 
            # code...
            $category_id = $books[$i]['category_id'];
            if($category_id){
                $category = $this->get_category($category_id, true);
                $books[$i]['category'] = $category;
            }

            $tags = $this->get_tags_by_book_id($books[$i]['id']);
            $books[$i]['tags'] = $tags;
            $books[$i]['wishlisted'] = $this->wishlisted($books[$i]['id'], $user_id);
        }

        return response()->json([
            'status' => true,
            "data"=>[
                "books" => $books
            ]
        ]);
    }
    public function books_physical(Request $request){
        $conn = app(\PDO::class);

        $user = $request->user();
        $user_id = $user->id;

        $books_physical = $this->get_physical_books($user_id);
        for ($i=0; $i < count($books_physical); $i++) { 
            # code...
            $category_id = $books_physical[$i]['category_id'];
            if($category_id){
                $category = $this->get_category($category_id, true);
                $books_physical[$i]['category'] = $category;
            }
            $tags = $this->get_tags_by_book_id($books_physical[$i]['id']);
            $books_physical[$i]['tags'] = $tags;
            $books_physical[$i]['wishlisted'] = $this->wishlisted($books_physical[$i]['id'], $user_id);
        }
        
        return response()->json([
            'status' => true,
            "data"=>[
                "books" => $books_physical
            ]
        ]);
    }
    public function books_digital(Request $request){
        $conn = app(\PDO::class);

        $user = $request->user();
        $user_id = $user->id;

        $books_digital = $this->get_digital_books($user_id);
        for ($i=0; $i < count($books_digital); $i++) { 
            # code...
            $category_id = $books_digital[$i]['category_id'];
            if($category_id){
                $category = $this->get_category($category_id, true);
                $books_digital[$i]['category'] = $category;
            }
            $tags = $this->get_tags_by_book_id($books_digital[$i]['id']);
            $books_digital[$i]['tags'] = $tags;
            $books_digital[$i]['wishlisted'] = $this->wishlisted($books_digital[$i]['id'], $user_id);
        }
        return response()->json([
            'status' => true,
            "data"=>[
                "books" => $books_digital
            ]
        ]);
    }

    // get request by id
    public function read_book_all(Request $request, $id){
        $validator = Validator::make(array('id' => $id), [
            'id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $res = $this->get_book($id);        
        if(is_bool($res)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Not found"
                ]
            ]);
        }

        $user = $request->user();
        $user_id = $user->id;

        $type = $res['type'];
        if($type == "physical"){
            $res = $this->get_physical_book($id, $user_id);
            Log::info($res);
        }
        else if ($type == "digital"){
            $res = $this->get_digital_book($id, $user_id);
            Log::info($res);
        }
        
        if(!is_bool($res)) {
            if($res['category_id']){
                $category_id=$res['category_id'];
                $category = $this->get_category($category_id, true);
                $res['category'] = $category;
            }
            $tags = $this->get_tags_by_book_id($res['id']);
            $res['tags'] = $tags;
            $res['wishlisted'] = $this->wishlisted($res['id'], $user_id);

            return response()->json([
                "status"=> true,
                "data" => [
                    "book" => $res
                ]
            ]);
        }
        else{
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => "There is an error on our end",
                ]
            ]);
        }

    }
    public function read_book_physical(Request $request, $id){
        $validator = Validator::make(array('id' => $id), [
            'id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }
        
        $res = $this->get_book($id);
        if(is_bool($res) || (isset($res) && $res['type'] != 'physical')){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Not found"
                ]
            ]);
        }

        $user = $request->user();
        $user_id = $user->id;

        $res = $this->get_physical_book($res['id'], $user_id);
        return response()->json([
            "status"=> true,
            "data" => [
                "book" => $res
            ]
        ]);
    }
    public function read_book_digital(Request $request, $id){
        $validator = Validator::make(array('id' => $id), [
            'id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $res = $this->get_book($id);
        if(is_bool($res) || (isset($res) && $res['type'] != 'digital')){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Not found"
                ]
            ]);
        }

        $user = $request->user();
        $user_id = $user->id;

        $res = $this->get_digital_book($id, $user_id);
        return response()->json([
            "status"=> true,
            "data" => [
                "book" => $res
            ]
        ]);
    }

    // update
    public function update_book(Request $request){
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'type' => 'required',
            'title' => 'required',
            'isbn' => 'required',
            'author' => 'required',
            'description' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }
        
        $book_keys = [
            "type",
            "title",
            "isbn",
            "author",
            "description",
        ];
        $physical_keys = [
            "copy_number"
        ];

        $digital_keys = [
            "download_url"
        ];

        try {
            $res = $this->get_book($request->input('id'));
            if(is_bool($res)){
                return response()->json([
                    "status" => false,
                    "errors" => [
                        "Not found"
                    ]
                ]);
            }

            $id = $res['id'];

            $conn = app(\PDO::class);
            $conn->beginTransaction();

            $update_stmt = $conn->prepare("update books set type=?, title=?, isbn=?, author=?, description=? where id=?");

            $request_array = $request->all();
            $array = array_intersect_key($request_array, array_flip($book_keys));
            $values = array_values($array);
            array_push($values, $id);
            $update_stmt->execute($values);

            $incoming_type = $request->type;
            if($incoming_type == $res['type']){ // same
                $add_stmt = null;
                if($incoming_type == "digital"){
                    $validator = Validator::make(["download_url" => $request->input('download_url')], [
                        'download_url' => 'required',
                    ]);
            
                    if($validator->fails()){
                        return response()->json([
                            "status" => false,
                            "errors" => $validator->errors()
                        ], 422);
                    }
                    $add_stmt = $conn->prepare("update digital_books set download_url=? where id=?;");
                    $add_stmt->execute(array($request->input("download_url"), $id));
                }
                else if($incoming_type == "physical"){
                    $validator = Validator::make(["copy_number" => $request->input('copy_number')], [
                        'copy_number' => 'required',
                    ]);
            
                    if($validator->fails()){
                        return response()->json([
                            "status" => false,
                            "errors" => $validator->errors()
                        ], 422);
                    }
                    $add_stmt = $conn->prepare("update physical_books set copy_number=? where id=?;");
                    $add_stmt->execute(array($request->input("copy_number"), $id));
                }
            }
            else{ // different types
                $delete_stmt = null;
                $add_stmt = null;
                if($incoming_type == "digital"){
                    $delete_stmt = $conn->prepare("delete from physical_books where id=?;");
                    $delete_stmt->execute(array($id));

                    $validator = Validator::make(["download_url" => $request->input('download_url')], [
                        'download_url' => 'required',
                    ]);
            
                    if($validator->fails()){
                        return response()->json([
                            "status" => false,
                            "errors" => $validator->errors()
                        ], 422);
                    }
                    $add_stmt = $conn->prepare("insert into digital_books(id, download_url) values(?, ?);");
                    $add_stmt->execute(array($id, $request->input('download_url')));
                }
                else if($incoming_type == "physical"){
                    $delete_stmt = $conn->prepare("delete from digital_books where id=?");
                    $delete_stmt->execute(array($id));

                    $validator = Validator::make(["copy_number" => $request->input('copy_number')], [
                        'copy_number' => 'required',
                    ]);
            
                    if($validator->fails()){
                        return response()->json([
                            "status" => false,
                            "errors" => $validator->errors()
                        ], 422);
                    }
                    $add_stmt = $conn->prepare("insert into physical_books(id, copy_number) values(?, ?);");
                    $add_stmt->execute(array($id, $request->input('copy_number')));
                }
            }
            $conn->commit();
            
            return redirect()->route('book.all', [
                'id' => $id,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => $th->getMessage(),
                    "line" => $th->getLine(),
                ]
            ]);
        }
    }

    // delete
    public function delete_book($id){
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $res = $this->get_book($id);
        if(is_bool($res)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Not found"
                ]
            ]);
        }

        $conn = app(\PDO::class);
        $stmt = $conn->prepare("delete from books where id=?");
        $stmt->execute(array($id));
        $delete_stmt = null;

        if($res['type'] == "digital"){
            $delete_stmt = $conn->prepare('delete from digital_books where id=?;');
        }
        else if($res['type'] == "physical"){
            $delete_stmt = $conn->prepare('delete from physical_books where id=?;');
        }
        $delete_stmt->execute(array($id));

        if($stmt->rowCount() != 1 || $delete_stmt->rowCount() !== 1){
            Log::info([$stmt->rowCount(), $delete_stmt->rowCount()]);
            return response()->json([
                "status" => false,
                "errors" => [
                    "Could not delete the book"
                ]
            ]);
        }
        else{
            return response()->json([
                "status" => true,
                "message" => "Successfully deleted",
            ]);
        }
    }

    // link & unlink category
    public function link_category(Request $request){
        $validator = Validator::make($request->all(), [
            'book_id' => 'required|integer',
            'category_id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);


        }

        $book = $this->get_book($request->book_id);
        if(is_bool($book)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Book not found"
                ]
            ]);
        }

        $category = $this->get_category($request->category_id);
        if(is_bool($category)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Category not found"
                ]
            ]);
        }

        try {
            $conn = app(\PDO::class);
            $stmt = $conn->prepare("insert into category_book values(?, ?) on duplicate key update category_id=category_id");
            $stmt->execute(array($request->category_id, $request->book_id));
            
            return response()->json([
                "status" => true,
                "message" => "Successfully linked"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => $th->getMessage(),
                    "line" => $th->getLine(),
                ]
            ]);
        }

    }
    public function unlink_category(Request $request){
        $validator = Validator::make($request->all(), [
            'book_id' => 'required|integer',
            'category_id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);


        }

        $book = $this->get_book($request->book_id);
        if(is_bool($book)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Book not found"
                ]
            ]);
        }

        $category = $this->get_category($request->category_id);
        if(is_bool($category)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Category not found"
                ]
            ]);
        }

        try {
            $conn = app(\PDO::class);
            $stmt = $conn->prepare("delete from category_book where category_id=? and book_id=?");
            $stmt->execute(array($request->category_id, $request->book_id));
            
            return response()->json([
                "status" => true,
                "message" => "Successfully unlinked"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => $th->getMessage(),
                    "line" => $th->getLine(),
                ]
            ]);
        }
    }

    // link & unlink tag
    public function link_tag(Request $request){
        $validator = Validator::make($request->all(), [
            'book_id' => 'required|integer',
            'tag_id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);


        }

        $book = $this->get_book($request->book_id);
        if(is_bool($book)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Book not found"
                ]
            ]);
        }

        $tag = $this->get_tag($request->tag_id);
        if(is_bool($tag)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Tag not found"
                ]
            ]);
        }

        try {
            $conn = app(\PDO::class);
            $stmt = $conn->prepare("insert into tag_book values(?, ?) on duplicate key update tag_id=tag_id");
            $stmt->execute(array($request->tag_id, $request->book_id));
            
            return response()->json([
                "status" => true,
                "message" => "Successfully linked"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => $th->getMessage(),
                    "line" => $th->getLine(),
                ]
            ]);
        }

    }
    public function unlink_tag(Request $request){
        $validator = Validator::make($request->all(), [
            'book_id' => 'required|integer',
            'tag_id' => 'required|integer',
        ]);
        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);


        }

        $book = $this->get_book($request->book_id);
        if(is_bool($book)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Book not found"
                ]
            ]);
        }

        $tag = $this->get_tag($request->tag_id);
        if(is_bool($tag)){
            return response()->json([
                "status" => false,
                "errors" => [
                    "Tag not found"
                ]
            ]);
        }

        try {
            $conn = app(\PDO::class);
            $stmt = $conn->prepare("delete from tag_book where tag_id=? and book_id=?");
            $stmt->execute(array($request->tag_id, $request->book_id));
            
            return response()->json([
                "status" => true,
                "message" => "Successfully unlinked"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "status" => false,
                "errors" => [
                    "message" => $th->getMessage(),
                    "line" => $th->getLine(),
                ]
            ]);
        }
    }

    // search
    public function search(Request $request){
        $user = $request->user();

        $user_id = $user->id;
        $keyword = $request->keyword;

        $books_physical = $this->get_physical_books_search($user_id, $keyword);
        $books_digital = $this->get_digital_books_search($user_id, $keyword);
        $books = array_merge($books_physical, $books_digital);

        for ($i=0; $i < count($books); $i++) { 
            # code...
            $category_id = $books[$i]['category_id'];
            if($category_id){
                $category = $this->get_category($category_id, true);
                $books[$i]['category'] = $category;
            }

            $tags = $this->get_tags_by_book_id($books[$i]['id']);
            $books[$i]['tags'] = $tags;
        }

        return response()->json([
            'status' => true,
            "data"=>[
                "books" => $books
            ]
        ]);
    }

    // TODO
    public function download_by_id($id){
        return 'hello';
    }

    // functions
    private function get_physical_books($user_id){
        $conn = app(\PDO::class);
        $books_stmt = $conn->prepare("select books.*, physical_books.*, details.status as booking_details_status, cb.category_id category_id from books
        natural join physical_books 
        left outer join category_book  as cb on cb.book_id = books.id
        left outer join details on details.book_id=books.id and details.user_id=? && details.return_date is null");
        $books_stmt->execute(array($user_id));
        $books_physical = $books_stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $books_physical;
    }

    private function get_digital_books($user_id){
        $conn = app(\PDO::class);

        $books_stmt = $conn->prepare("select books.*, digital_books.*, details.status as booking_details_status, cb.category_id category_id from books
        natural join digital_books 
        left outer join category_book  as cb on cb.book_id = books.id
        left outer join details on details.book_id = books.id and details.user_id=? && details.return_date is null;");
        $books_stmt->execute(array($user_id));
        $books_digital = $books_stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $books_digital;
    }

    private function get_physical_books_search($user_id, $search){
        $conn = app(\PDO::class);
        $books_stmt = $conn->prepare("select books.*, physical_books.*, details.status as booking_details_status, cb.category_id category_id from books
        natural join physical_books 
        left outer join details on details.book_id = books.id 
        left outer join category_book  as cb on cb.book_id = books.id
        where details.return_date is null and (details.user_id=? || details.user_id is null) and (books.title like ? || books.isbn like ? || books.author like ? || books.description like ?);");
        $books_stmt->execute(array($user_id, "%$search%", "%$search%", "%$search%", "%$search%"));
        $books_physical = $books_stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $books_physical;
    }

    private function get_digital_books_search($user_id, $search){
        $conn = app(\PDO::class);

        $books_stmt = $conn->prepare("select books.*, digital_books.*, details.status as booking_details_status, cb.category_id category_id from books
        natural join digital_books 
        left outer join details on details.book_id = books.id 
        left outer join category_book  as cb on cb.book_id = books.id
        where details.return_date is null and (details.user_id=? || details.user_id is null)  and (books.title like ? || books.isbn like ? || books.author like ? || books.description like ?);");
        $books_stmt->execute(array($user_id, "%$search%", "%$search%", "%$search%", "%$search%"));
        $books_digital = $books_stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $books_digital;
    }

    private function get_book($id){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select books.*, category_book.category_id category_id from books left outer join category_book on category_book.book_id = books.id where books.id = ? limit 1");
        $stmt->execute(array($id));
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res;
    }

    private function get_physical_book($id, $user_id){
        $conn = app(\PDO::class);

        $stmt = $conn->prepare("select books.*, physical_books.*, details.status as booking_details_status, category_book.category_id category_id
        from books 
        natural join physical_books 
        left outer join category_book on category_book.book_id = books.id 
        left outer join details on details.book_id=books.id and details.user_id=? && details.return_date is null
        where books.id = ?");
        $stmt->execute(array($user_id, $id));
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res;
    }

    private function get_digital_book($id, $user_id){
        $conn = app(\PDO::class);

        $stmt = $conn->prepare("select books.*, digital_books.*, details.status as booking_details_status, category_book.category_id category_id
        from books 
        natural join digital_books 
        left outer join category_book on category_book.book_id = books.id 
        left outer join details on details.book_id=books.id and details.user_id=? && details.return_date is null
        where books.id = ?");
        $stmt->execute(array($user_id, $id));
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res;
    }

    private function get_category($id, $full = false){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select * from categories where id=?");
        $stmt->execute(array($id));
        $category = $stmt->fetch(\PDO::FETCH_ASSOC);

        if(!$full || !$category){
            return $category;
        }

        $parent_id = $category['parent_id'];
        $parents = [];
        while (true) {
            if(!isset($parent_id)){
                break;
            }
        
            $stmt = $conn->prepare("select * from categories where id=?");
            $stmt->execute(array($parent_id));
            $parent = $stmt->fetch(\PDO::FETCH_ASSOC);
            if(is_bool($parent)){
                break;
            }
            array_push($parents, $parent);
            $parent_id = $parent['parent_id'];
        }
        $parents_reversed = [];
        if(count($parents)){
            $parents_reversed = array_reverse($parents);
        }
    
        $category['parents'] = $parents_reversed;
        return $category;
    }

    private function get_tag($id){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select * from tags where id=?");
        $stmt->execute(array($id));
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res;
    }

    private function get_tags_by_book_id($book_id){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select tags.* from tag_book inner join tags on tags.id = tag_book.tag_id where tag_book.book_id=?");
        $stmt->execute(array($book_id));
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if(is_bool($res)){
            return [];
        }

        return $res;
    }

    private function wishlisted($book_id, $user_id){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select * from wishlists where book_id=? and user_id=?");
        $stmt->execute(array($book_id, $user_id));
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        if(is_bool($res)){
            return false;
        }
        else{
            return true;
        }
    }
}
