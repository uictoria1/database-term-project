<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TagController extends Controller
{
  public function __construct(){
    $this->middleware("auth");
  }

  public function create(Request $request){
    $validator = Validator::make($request->all(), [
      'name' => 'required|string|max:255',
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }

    $conn = app(\PDO::class);
    try {
      $stmt = $conn->prepare("insert into tags(name) values(?);");
      $stmt->execute(array($request->name));

      $id = $conn->lastInsertId();
      $tag = $this->get_tag($id);
      return response()->json([
        "status" => true,
        "message" => "You successfully created a tag",
        "data" => [
          "tag" => $tag
        ]
      ]);
    } catch (\Throwable $th) {
      return response()->json([
        "status" => false,
        "errors" => [
            $th->getMessage()
        ]
      ]);
    }
  }

  public function get_all(Request $request){
    $conn = app(\PDO::class);
    $stmt = $conn->query("select * from tags");
    $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return response()->json([
      "status" => true,
      "data" => [
        "tags" => $res
      ]
    ]);
  }

  public function get_by_id($id){
    $validator = Validator::make(["id" => $id], [
      'id' => 'required|integer',
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }

    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from tags where id=?");
    $stmt->execute(array($id));
    $res = $stmt->fetch(\PDO::FETCH_ASSOC);
    return response()->json([
      "status" => true,
      "data" => [
        "tag" => $res
      ]
    ]);
  }

  public function update(Request $request){
    $validator = Validator::make($request->all(), [
      'id' => 'required|integer',
      'name' => 'required|string|max:255'
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }

    try {
      $conn = app(\PDO::class);
      $stmt = $conn->prepare("update tags set name=? where id=?");
      $stmt->execute(array($request->name, $request->id));

      $tag = $this->get_tag($request->id);
      return response()->json([
        "status" => true,
        "data" => [
          "tag" => $tag
        ]
      ]);
    } catch (\Throwable $th) {
      return response()->json([
        "status" => false,
        "errors" => [
            $th->getMessage()
        ]
      ]);
    }
  }

  public function delete($id){
    $validator = Validator::make(["id" => $id], [
      'id' => 'required|integer',
    ]);
    if($validator->fails()){
      return response()->json([
          "status" => false,
          "errors" => $validator->errors()
      ], 422);
    }

    $conn = app(\PDO::class);
    $stmt = $conn->prepare("delete from tags where id=?");
    $stmt->execute(array($id));

    return response()->json([
      "status" => true,
      "message" => "You successfully deleted a tag"
    ]); 
  }

  private function get_tag($id){
    $conn = app(\PDO::class);
    $stmt = $conn->prepare("select * from tags where id=?");
    $stmt->execute(array($id));
    return $stmt->fetch(\PDO::FETCH_ASSOC);
  }

}