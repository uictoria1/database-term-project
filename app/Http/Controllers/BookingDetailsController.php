<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookingDetailsController extends Controller
{
  public function __construct(){
    $this->middleware('auth');
  }

  public function create(Request $request){
    $validator = Validator::make($request->all(), [
      'book_id' => 'required|integer',
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }
    $book_id = $request->book_id;

    $user = $request->user();
    $user_id = $user->id;

    $restricted = $this->user_restricted($user_id);
    if(!is_bool($restricted) && isset($restricted['end_date'])){
      return response()->json([
        "status" => false,
        "errors" => [
          "User is restricted"
        ]
      ]);
    }

    $book = $this->get_book($request->book_id);
    if(is_bool($book)){
      return response()->json([
        "status" => false,
        "errors" => [
          "There is no such book"
        ]
      ]);
    }

    $detail = $this->get_detail($book_id, $user_id);
    if(!is_bool($detail)){
      return response()->json([
        'status' => false,
        "errors" => [
          'You already booked and your status is '.$detail['status']
        ]
      ]);
    }

    try {
      if($book['type'] == "digital"){
        $this->create_booking($book_id, $user_id, "reading");
        return response()->json([
          "status" => true,
          "message" => "You successfully booked an item",
        ]);
      }
      else{
        $copy_number = $book['copy_number'];
        $reading_count = $this->get_reading_count($book['id'])['count'];
        if($copy_number > 0){
          if($copy_number <= $reading_count){
            $this->create_booking($book_id, $user_id, "pending");
            return response()->json([
              "status" => true,
              "message" => "You successfully booked an item",
            ]);
          }
          else{
            $this->create_booking($book_id, $user_id, "reading");
            return response()->json([
              "status" => true,
              "message" => "You successfully booked an item",
            ]);
          }
        }
        else{
          $this->create_booking($book_id, $user_id, "pending");
          return response()->json([
            "status" => true,
            "message" => "You successfully booked an item",
          ]);
        }
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

  public function get(Request $request){
    $user = $request->user();
    $user_id = $user->id;

    $details = $this->get_details($user_id);
    return response()->json([
      'status' => true,
      'booking' => $details,
    ]);
  }

  public function cancel(Request $request){
    $validator = Validator::make($request->all(), [
      'id' => 'required|integer'
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }
    $book_id = $request->id;
    
    try {
      //code...
      $user = $request->user();
      $user_id = $user->id;
      
      $conn = app(\PDO::class);
      $conn->beginTransaction();
      $stmt = $conn->prepare("select * from details where book_id=? and user_id=? and status = 'pending';");
      $stmt->execute(array($book_id, $user_id));
      $res = $stmt->fetch(\PDO::FETCH_ASSOC);
  
      if(is_bool($res)){
        return response()->json([
          'status' => false,
          "errors" => ["There is nothing to cancel"]
        ]);
      }
  
      $stmt = $conn->prepare("update details set status = 'cancelled' where id=?");
      $stmt->execute(array($res['id']));
      $conn->commit();
  
      $row_count = $stmt->rowCount();
      if($row_count > 0){
        return response()->json([
          'status' => true,
          "message" => "Successfully cancelled a booking"
        ]);
      }
      else{
        return response()->json([
          'status' => false,
          "errors" => ["There was an error while cancelling the booking"]
        ]);
      }
    } catch (\Throwable $th) {
      return response()->json([
        'status' => false,
        "errors" => [
          "message" => $th->getMessage(), 
          'line' => $th->getLine()
        ]
      ]);
    }
  }

  public function return(Request $request){
    $validator = Validator::make($request->all(), [
      'id' => 'required|integer'
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }
    $book_id = $request->id;

    $conn = app(\PDO::class);
    try {
      //code...
      $user = $request->user();
      $user_id = $user->id;
      
      $conn->beginTransaction();
      $stmt = $conn->prepare("select * from details where book_id=? and user_id=? and status = 'reading';");
      $stmt->execute(array($book_id, $user_id));
      $res = $stmt->fetch(\PDO::FETCH_ASSOC);
  
      if(is_bool($res)){
        return response()->json([
          'status' => false,
          "errors" => ["There is nothing to return"]
        ]);
      }
      
      $return_date = Carbon::now();
      $stmt = $conn->prepare("update details set status = 'returned', return_date = ? where id=?");
      $stmt->execute(array($return_date, $res['id']));

      $pending_stmt = $conn->prepare("select * from details where book_id=? and status='pending' order by id asc;");
      $pending_stmt->execute(array($book_id));
      $pending_res = $pending_stmt->fetchAll(\PDO::FETCH_ASSOC);
      if(is_bool($pending_res)){
        $conn->commit();
        return response()->json([
          'status' => true,
          "message" => "Successfully returned book"
        ]);
      }

      foreach ($pending_res as $row) {
        $restricted = $this->user_restricted($row['user_id']);
        if(!is_bool($restricted) && isset($restricted['end_date'])){
          continue;
        }
        else{
          $end_date = Carbon::now()->addDays(15);
          $pending_to_reading_stmt = $conn->prepare("update details set status='reading', end_date=? where id=?;");
          $pending_to_reading_stmt->execute(array($end_date, $row['id']));
          $row_count = $pending_to_reading_stmt->rowCount();
          if($row_count == 0){
            throw new \Exception("There is error while reordering the queue");
          }
          else{
            $conn->commit();
            return response()->json([
              'status' => true,
              "message" => "Successfully returned book"
            ]);
          }
        }
      }
      
      $conn->commit();
      return response()->json([
        'status' => true,
        "message" => "Successfully returned book"
      ]);
    } catch (\Throwable $th) {
      if($conn->inTransaction()){
        $conn->rollback();
      }
      return response()->json([
        'status' => false,
        "errors" => [
          "message" => $th->getMessage(), 
          'line' => $th->getLine()
        ]
      ]);
    }
  }

  private function user_restricted($user_id){
    $conn = app(\PDO::class);
    $exists_stmt = $conn->prepare("select restrictions.* from users left outer join restrictions on users.id = restrictions.id where users.id=?");
    $exists_stmt->execute(array($user_id));
    $result  = $exists_stmt->fetch(\PDO::FETCH_ASSOC);
    return $result;
  }

  private function user_exists($user_id){
    $conn = app(\PDO::class);
    $exists_stmt = $conn->prepare("select 1 from users where id=?");
    $exists_stmt->execute(array($user_id));
    $result  = $exists_stmt->fetch(\PDO::FETCH_ASSOC);
    return $result;
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
  
  private function get_reading_count($book_id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select count(*) as count from details where book_id = ? and status = 'reading'");
    $stmt->execute(array($book_id));
    return $stmt->fetch(\PDO::FETCH_ASSOC);
  }

  private function create_booking($book_id, $user_id, $status){
    $start_date = Carbon::now();
    $end_date = Carbon::now()->addDays(15);
    $conn = app(\PDO::class);
    if($status == "reading"){
      $stmt = $conn->prepare("insert into details (book_id, user_id, start_date, end_date, status) values(?, ?, ?, ?, ?);");
      $stmt->execute(array($book_id, $user_id, $start_date, $end_date, $status));
      $row_count = $stmt->rowCount();
    }
    else{
      $stmt = $conn->prepare("insert into details (book_id, user_id, start_date, status) values(?, ?, ?, ?);");
      $stmt->execute(array($book_id, $user_id, $start_date, $status));
      $row_count = $stmt->rowCount();
    }

    return $row_count;
  }

  private function get_details($user_id){
    $conn = app(\PDO::class);
    $phys = $conn->prepare("select books.*, physical_books.*, details.status as booking_details_status from books
    natural join physical_books 
    inner join details on details.book_id = books.id 
    where details.return_date is null and details.user_id=?;");
    $phys->execute(array($user_id));
    $phys_res = $phys->fetchAll(\PDO::FETCH_ASSOC);

    $dig = $conn->prepare("select books.*, digital_books.*, details.status as booking_details_status from books
    natural join digital_books 
    inner join details on details.book_id = books.id 
    where details.return_date is null and details.user_id=?;");
    $dig->execute(array($user_id));
    $dig_res = $dig->fetchAll(\PDO::FETCH_ASSOC);

    Log::info([$phys_res, $dig_res]);
    if(is_bool($phys_res)){
      if(is_bool($dig_res)){
        return null;
      }
      else{
        return $dig_res;
      }
    }
    else{
      if(is_bool($dig_res)){
        return $phys_res;
      }
      else{
        array_merge($phys_res, $dig_res);
        return $phys_res;
      }
    }
  }

}