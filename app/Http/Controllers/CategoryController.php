<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
  public function __construct(){
    $this->middleware('auth');
  }

  public function create(Request $request){
    $validator = Validator::make($request->all(), [
      'name' => 'string|max:255'
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    $conn = app(\PDO::class);
    try {
      //code...
      $conn->beginTransaction();
  
      $exist_stmt = $conn->prepare("select 1 from categories where name=?");
      $exist_stmt->execute(array($request->name));
      $res = $exist_stmt->fetchAll(\PDO::FETCH_ASSOC);
  
      if(is_bool($res)){
        $conn->rollback();
        return response()->json([
          "status" => false,
          "errors" => [
            "There is already a category with the same name"
          ]
        ]);
      }
  
      $stmt = $conn->prepare("insert into categories(name) values(?)");
      $stmt->execute(array($request->name));
  
      $row_count = $stmt->rowCount();
      if($row_count > 0){
        $conn->commit();
        return response()->json([
          'status' => true,
          "message" => "Successfully created"
        ]);
      }
      else{
        $conn->rollback();
        return response()->json([
          'status' => false,
          "message" => "There was an error while creating a category"
        ]);
      }
    } catch (\Throwable $th) {
      //throw $th;
      $conn->rollback();
      return response()->json([
        'status' => false,
        "errors" => [
          "message" => $th->getMessage(), 
          'line' => $th->getLine()
        ]
      ]);
    }
  }

  public function getAll(){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select cat1.*, cat2.name as parent_name from categories as cat1 left outer join categories cat2
    on cat1.parent_id = cat2.id;");
    $stmt->execute();
    $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if(is_bool($categories)){
      return response()->json([
        "status" => true,
        "data" => [
          "categories" => []
        ]
      ]);
    }
    return response()->json([
      "status" => true,
      "data" => [
        "categories" => $categories
      ]
    ]);
  }

  public function getById($id){
    $validator = Validator::make(["id" => $id], [
      'id' => 'required|integer'
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    try {
      //code...
      $conn = app(\PDO::class);
      $stmt = $conn->prepare("select * from categories where id=?");
      $stmt->execute(array($id));
      $category = $stmt->fetch(\PDO::FETCH_ASSOC);
      if(is_bool($category)){
        return response()->json([
          'status' => false,
          "errors" => [
            "Not found"
          ]
        ]);
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
  
      return response()->json([
        'status' => true,
        "data" => [
          "category" => $category,
          "parents" => $parents_reversed
        ]
      ]);
    } catch (\Throwable $th) {
      //throw $th;
      return response()->json([
        'status' => false,
        "errors" => [
          "message" => $th->getMessage(), 
          'line' => $th->getLine()
        ]
      ]);
    }
  }

  public function update(Request $request){
    $validator = Validator::make($request->all(), [
      'id' => 'required|integer',
      "name" => "required|string|max:255"
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    $category = $this->get_category($request->id);
    if(is_bool($category)){
      return response()->json([
        "status" => false,
        "errors" => [
          "Not found"
        ]
      ]); 
    }

    try {
      $parent_id = $request->parent_id;
      if(isset($parent_id)){
        $parent_category = $this->get_category($parent_id);
        if(is_bool($category)){
          return response()->json([
            "status" => false,
            "errors" => [
              "Parent Category not found"
            ]
          ]); 
        }

        $conn = app(\PDO::class);
        $stmt = $conn->prepare("update categories set name=?, parent_id=? where id=?;");
        $stmt->execute(array($request->name, $parent_id, $request->id));

        $category = $this->get_category($request->id);
        return response()->json([
          "status" => true,
          "data" => [
            "category" => $category
          ]
        ]);
      }
      else{
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("update categories set name=? where id=?;");
        $stmt->execute(array($request->name, $request->id));
  
        $category = $this->get_category($request->id);
        return response()->json([
          "status" => true,
          "data" => [
            "category" => $category
          ]
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
    };
  }
  
  public function delete($id){
    $validator = Validator::make(["id" => $id], [
      'id' => 'required|integer'
    ]);
    if($validator->fails()){
      return response()->json([
        "status" => false,
        "errors" => $validator->errors()
      ], 422);
    }

    try {
      //code...
      $conn = app(\PDO::class);
      $stmt = $conn->prepare("delete from categories where id=?");
      $stmt->execute(array($id));
      $row_count = $stmt->rowCount();
      if($row_count > 0){
        return response()->json([
          'status' => true,
          "message" => "Successfully deleted"
        ]);
      }
      else{
        return response()->json([
          'status' => false,
          "errors" => ["Something went wrong while deleting"]
        ]);
      }
    } catch (\Throwable $th) {
      //throw $th;
      return response()->json([
        'status' => false,
        "errors" => [
          "message" => $th->getMessage(), 
          'line' => $th->getLine()
        ]
      ]);
    }
  }

  private function get_category($id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from categories where id=?");
    $stmt->execute(array($id));
    $res = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $res;
  }
}