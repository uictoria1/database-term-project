<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WishlistController extends Controller
{
  public function __construct(){
    $this->middleware("auth");
  }

  public function link(Request $request){
    $validator = Validator::make($request->all(), [
      "book_id" => "required|integer"
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    $user = $request->user();
    $user_id = $user->id;

    $book = $this->get_book($request->book_id);
    if(is_bool($book)){
      return response()->json([
        "status" => false,
        "errors" => ["Book not found"]
      ]);
    }

    $conn = app(\PDO::class);
    $stmt = $conn->prepare("insert into wishlists values(?,?) on duplicate key update user_id=?;");
    $stmt->execute(array($user_id, $request->book_id, $user_id));

    $wishlist = $this->get_wishlist($user_id, $request->book_id);
    return response()->json([
      "status" => true,
      "data" => [
        "wishlist" => $wishlist
      ]
    ]);
  }

  public function unlink(Request $request){
    $validator = Validator::make($request->all(), [
      "book_id" => "required|integer"
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    $user = $request->user();
    $user_id = $user->id;

    $book = $this->get_book($request->book_id);
    if(is_bool($book)){
      return response()->json([
        "status" => false,
        "errors" => ["Book not found"]
      ]);
    }

    $conn = app(\PDO::class);
    $stmt = $conn->prepare("delete from wishlists where user_id=? and book_id=?");
    $stmt->execute(array($user_id, $request->book_id));

    $wishlists = $this->get_wishlists($user_id);
    return response()->json([
      "status" => true,
      "data" => [
        "wishlist" => $wishlists
      ]
    ]);
  }
  public function get(Request $request){
    $user = $request->user();
    $wishlists = $this->get_wishlists($user->id);
    return response()->json([
      "status" => true,
      "data" => [
        "wishlists" => $wishlists
      ]
    ]);
  }

  private function get_book($id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from books where books.id = ? limit 1");
    $stmt->execute(array($id));
    $res = $stmt->fetch(\PDO::FETCH_ASSOC);
    if(!is_bool($res)){
      if($res['type'] == 'digital'){
        return $this->get_digital_book($res['id']);
      }
      else{
        return $this->get_physical_book($res['id']);
      }
    }
    return $res;
  }
  private function get_physical_book($id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from books natural join physical_books where books.id = ?;");
    $stmt->execute(array($id));
    $res = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $res;
  }
  private function get_digital_book($id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from books natural join digital_books where books.id = ?;");
    $stmt->execute(array($id));
    $res = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $res;
  }

  private function get_detail($book_id, $user_id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from details where book_id=? and user_id = ? and (status != 'returned' && status != 'cancelled')");
    $stmt->execute(array($book_id, $user_id));
    return $stmt->fetch(\PDO::FETCH_ASSOC);
  }

  private function get_wishlist($user_id, $book_id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from wishlists where user_id=? and book_id=?");
    $stmt->execute(array($user_id, $book_id));
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);

    if(is_bool($result)){
      return $result;
    }

    $book = $this->get_book($book_id);
    if(is_bool($book)){
      return [];
    }
    if($book['type'] == "digital"){
      $book = $this->get_digital_book($book_id);
    }
    else{
      $book = $this->get_physical_book($book_id);
    }

    $detail = $this->get_detail($user_id, $book_id);
    if(is_bool($detail)){
      $book['booking_detail_status'] = null;
      return $book;
    }

    $book['booking_detail_status'] = $detail['status'];
    return $book;
  }

  private function get_wishlists($user_id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("
    select books.*, physical_books.*, details.status as booking_detail_status from books
    natural join physical_books
    inner join wishlists on wishlists.book_id = books.id
    left outer join details on details.book_id = books.id
     where wishlists.user_id=?;
    ");
    $stmt->execute(array($user_id));
    $res1 = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("
    select books.*, digital_books.*, details.status as booking_detail_status from books
    natural join digital_books
    inner join wishlists on wishlists.book_id = books.id
    left outer join details on details.book_id = books.id
     where wishlists.user_id=?;
    ");
    $stmt->execute(array($user_id));
    $res2 = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $res1 = array_merge($res1, $res2);
    return $res1;
  }
}