<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RoleController extends Controller
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

    public function roles(){
      $conn = app(\PDO::class);
      $items_stmt = $conn->prepare("select * from roles;");
      $items_stmt->execute();
      $items = $items_stmt->fetchAll();
      return response()->json([
          'status' => true,
          "data"=>[
              "roles" => $items
          ]
      ]);
    }
}
