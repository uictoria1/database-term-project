<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function __construct(){
        $this->middleware("auth", ['except' => ['register', 'login', 'reset_db']]);
    }
    public function register(Request $request){
        $validator = Validator::make($request->all(), [
            'login' => 'required',
            'password' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        try {
            $exists = $this->user_exists($request->input('login'));
            if($exists){
                return response()->json([
                    "status" => false,
                    "errors" => [
                        "message" => "This login is taken already"
                    ]
                ], 409);
            }
    
            $password_hash = md5($request->input('password'));
            $token = $this->generate_token();
            $created_user = $this->create_user($request->input("login"), $password_hash, $token);
            if($created_user){
                $linked_role = $this->link_role($created_user);
                return response()->json([
                    'status' => true,
                    'message' => "User successfully created",
                    "api-token" => $token
                ]);
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

    public function login(Request $request){
        $validator = Validator::make($request->all(), [
            'login' => 'required',
            'password' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        try {
            $exists = $this->user_exists($request->input('login'));
            if(!$exists){
                return response()->json([
                    'status' => false,
                    "errors" => [
                        "We can't find user with these credentials"
                    ]
                ], 404);
            }

            $conn = app(\PDO::class);
            $right_password_stmt = $conn->prepare("Select * from users where login=:login && password=:password_hash limit 1");
            $right_password_stmt->execute(array(":login" => $request->input('login'), ":password_hash" => md5($request->input
            ('password'))));
            $result = $right_password_stmt->fetch(\PDO::FETCH_ASSOC);
            if(is_bool($result)){
                return response()->json([
                    "status" => false,
                    "errors" => [
                        "Invalid password"
                    ]
                ]);
            }

            $token = $this->generate_token();
            $token_stmt = $conn->prepare("update users set token=?, updated_date=? where id=?;");
            $token_stmt->execute(array($token, Carbon::now(), $result['id']));
            if($token_stmt->rowCount() == 1){

                $role_stmt = $conn->prepare("select roles.name from roles 
                    inner join role_user on role_user.role_id = roles.id 
                    inner join users on role_user.user_id = users.id 
                    where users.id = ?");
                $role_stmt->execute(array($result['id']));
                $role_res = $role_stmt->fetch();
                $role_type = $role_res['name'];

                return response()->json([
                    "status" => true,
                    "message" => "Succesfully authenticated",
                    "api-token" => $token,
                    "role" => $role_type,
                ]);
            }
            else{
                return response()->json([
                    "status" => false,
                    "errors" => [
                        "Oops. We could not authenticate"
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

    public function users(){
        $conn = app(\PDO::class);
        $items_stmt = $conn->prepare("select users.id, users.login, users.created_date, users.updated_date, restrictions.end_date as restricted_until, roles.name as role  from users left outer join restrictions on restrictions.id = users.id left outer join role_user on role_user.user_id = users.id inner join roles on roles.id = role_user.role_id order by users.id;");
        $items_stmt->execute();
        $items = $items_stmt->fetchAll(\PDO::FETCH_ASSOC);
        return response()->json([
            'status' => true,
            "data"=>[
                "users" => $items
            ]
        ]);
    }

    public function user_by_id($user_id){
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("select users.id, users.login, users.created_date, users.updated_date, restrictions.end_date as restricted_until, roles.name as role from users left outer join restrictions on restrictions.id = users.id left outer join role_user on role_user.user_id = users.id inner join roles on roles.id = role_user.role_id where users.id=?");
        $stmt->execute(array($user_id));
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if(is_bool($result)){
            return response()->json([
                'status' => false,
                "errors"=> [
                    "There is no user with this id"
                ]
            ], 404);
        }

        return response()->json([
            'status' => true,
            "data"=>[
                "user" => $result
            ]
        ]);
    }

    public function role_user(){
        $conn = app(\PDO::class);
        $items_stmt = $conn->prepare("select * from role_user;");
        $items_stmt->execute();
        $items = $items_stmt->fetchAll();
        return response()->json([
            'status' => true,
            "data"=>[
                "role_user" => $items
            ]
        ]);
    }

    // development
    public function reset_db(){
        $conn = app(\PDO::class);

        $conn->query("
            SET FOREIGN_KEY_CHECKS = 0;
            drop table if exists roles;
            drop table if exists users;
            drop table if exists role_user;
            drop table if exists books;
            drop table if exists digital_books;
            drop table if exists physical_books;
            drop table if exists restrictions;
            drop table if exists details;
            drop table if exists categories;
            drop table if exists wishlists;
            drop table if exists tags;
            drop table if exists tag_book;
            drop table if exists category_book;
            SET FOREIGN_KEY_CHECKS = 1;
        ");

        // ROLE
        $conn->query("CREATE TABLE roles(
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, 
                name VARCHAR(255) NOT NULL UNIQUE);
            ");

        // USER
        $conn->query("CREATE TABLE users(
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, 
                login VARCHAR(255) NOT NULL UNIQUE, 
                password VARCHAR(255) NOT NULL,
                token VARCHAR(255) NULL,
                created_date TIMESTAMP NOT NULL,
                updated_date TIMESTAMP NOT NULL)
            ");
        
        // ROLE TO USER
        $conn->query("CREATE TABLE role_user(
                role_id INT UNSIGNED NOT NULL, 
                user_id INT UNSIGNED NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users (id),
                FOREIGN KEY (role_id) REFERENCES roles (id)
            );");

        $conn->query("CREATE TABLE books(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type ENUM('digital', 'physical'),
            title TEXT NOT NULL,
            isbn TINYTEXT NOT NULL,
            author TEXT NOT NULL,
            description TEXT NULL
        )");

        // TODO ADD BOOK TYPE TABLE 

        $conn->query("CREATE TABLE digital_books(
            id INT UNSIGNED REFERENCES books(id),
            download_url TEXT NOT NULL
        )");

        $conn->query("CREATE TABLE physical_books(
            id INT UNSIGNED REFERENCES books(id),
            copy_number SMALLINT UNSIGNED NOT NULL default 0
        )");
        
        $insert_query = "
            INSERT INTO roles (name) values('client');
            INSERT INTO roles (name) values('admin');
        ";

        $conn->query($insert_query);

        
        $logins = [];
        $passwords = [];
        $user_length = 20;
        for ($i=0; $i < $user_length; $i++) { 
            $login = substr(sha1(mt_rand()),17,rand(10,15));
            if(!in_array($login, $logins)){
                $logins[] = $login;
                $passwords[] = md5($login);
            }
        }

        $query = [];
        for ($i=0; $i < count($logins); $i++) { 
            $login = $logins[$i];
            $password = $passwords[$i];
            $created_date = Carbon::now();
            $updated_date = Carbon::now();
            $stmt = $conn->prepare("INSERT INTO users(login, password, token, created_date, updated_date) 
                values(:login, :password, null, :created_date, :updated_date);");
            $stmt->execute(array(":login" => $login, ":password" => $password, ":created_date" => $created_date, ":updated_date" => $updated_date));
        }

        $roles_stmt = $conn->prepare('select id from roles;');
        $roles_stmt->execute();
        $roles_arr = $roles_stmt->fetchAll();
        
        $users = $conn->query("select id from users");
        foreach ($users as $user) {
            $id = $user['id'];
            $role_index = array_rand($roles_arr);
            $role_id = $roles_arr[$role_index]['id'];
            $stmt = $conn->prepare("insert into role_user(role_id, user_id) values(:role_id, :user_id);");
            $stmt->execute(array(":role_id" => $role_id, ":user_id" => $id));
        }

        $type = ['digital', 'physical'];
        $book_length = 20;
        for ($i=0; $i < $book_length; $i++) { 
            $t = $type[array_rand($type)];
            $title = "title ".$this->sentence(3);
            $isbn = $this->isbn();
            $author = "author ".$this->sentence(2);
            $description = "description ".$this->sentence(rand(50, 100));

            $stmt = $conn->prepare("insert into books(type, title, isbn, author, description) values(:type, :title, :isbn, :author, :description);");
            $stmt->execute(array(
                ":type" => $t,
                ":title" => $title,
                ":isbn" => $isbn,
                ":author" => $author,
                ":description" => $description,
            ));
        }

        $books_stm = $conn->prepare("select id, type from books");
        $books_stm->execute();
        $books_arr = $books_stm->fetchAll();

        foreach ($books_arr as $book) {
            $id = $book['id'];
            $type = $book['type'];

            if($type == "physical"){
                $stm = $conn->prepare("insert into physical_books values(:id, :copy_number);");
                $stm->execute(array(":id" => $id, ":copy_number" => rand(2,5)));
            }
            else{
                $stm = $conn->prepare("insert into digital_books values(:id, :download_url);");
                $stm->execute(array(":id" => $id, ":download_url" => "http://188.166.188.217/download/".$id));
            }
        }

        $conn->query("CREATE TABLE restrictions(
            id INT UNSIGNED primary key REFERENCES users(id),
            end_date TIMESTAMP DEFAULT NULL
        )");

        $insert_query = "
        INSERT INTO restrictions values(1, '2021-12-27 05:00:00');
        INSERT INTO restrictions values(2, '2021-12-22 13:00:00');
        ";

        $conn->query($insert_query);


        $conn->query("create table details(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, 
            user_id int unsigned references users(id),
            book_id int unsigned references books(id),
            start_date timestamp not null,
            end_date timestamp null,
            return_date timestamp null,
            status enum('pending', 'reading', 'returned', 'cancelled') default 'pending'
        )");

        $conn->query("create table categories(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, 
            name varchar(255) not null unique,
            parent_id int unsigned null,
            CONSTRAINT FOREIGN KEY (parent_id) REFERENCES categories(id)
            on delete cascade on update cascade
        )");

        $conn->query("create table wishlists(
            user_id int unsigned references users(id),
            book_id int unsigned references books(id),
            primary key(user_id, book_id)
        )");

        $conn->query("create table tags(
            id int unsigned AUTO_INCREMENT primary key,
            name varchar(255) unique not null
        )");

        $conn->query("create table tag_book(
            tag_id int unsigned references tags(id),
            book_id int unsigned references books(id),
            primary key(tag_id, book_id)
        )");

        $conn->query("create table category_book(
            category_id int unsigned references categories(id),
            book_id int unsigned references books(id),
            primary key(category_id, book_id),
            unique(book_id)
        )");
    }

    private function isbn(){
        $num = "";
        for ($i=0; $i < 10; $i++) { 
            $num = $num.rand(1,9);
        }
        return $num;
    }

    private function sentence($length){
        $str_l = [];
        for ($i=0; $i < $length; $i++) { 
            array_push($str_l, $this->word());
        }

        return implode(" ", $str_l);
    }

    private function word(){
        $length = rand(7,15);
        $str = "";
        for ($i=0; $i < $length; $i++) { 
            $str = $str.chr(rand(65,90));
        }
        return $str;
    }

    private function create_user($login, $password_hash, $token){
        $created_date = Carbon::now();
        $updated_date = Carbon::now();
        $conn = app(\PDO::class);
        $stmt = $conn->prepare("INSERT INTO users(login, password, token, created_date, updated_date) 
            values(:login, :password, :token, :created_date, :updated_date);");
        $stmt->execute(array(":login" => $login, ":password" => $password_hash, ":token" => $token, ":created_date" => $created_date, ":updated_date" => $updated_date));
        if($stmt->rowCount() > 0){
            return $conn->lastInsertId();
        }
        else{
            return null;
        }
    }

    private function link_role($user_id){
        $conn = app(\PDO::class);

        $link_role = $conn->prepare("insert into role_user(role_id, user_id) values(1, ?);");
        $link_role->execute(array($user_id));
        if($link_role->rowCount() > 0){
            return true;
        }
        else{
            return false;
        }
    }

    private function generate_token(){
        return sha1(mt_rand(1, 90000) . 'SALT');
    }

    private function user_exists($login){
        $conn = app(\PDO::class);
        $exists_stmt = $conn->prepare("select 1 from users where login=:login limit 1");
        $exists_stmt->execute(array(":login" => $login));
        $result = $exists_stmt->fetch();
        if(is_bool($result)){
            return false;
        }
        return true;
    }

    public function restrict_user(Request $request){
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
        ]);

        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $end_date = Carbon::now();
        $end_date->addDays(14);

        $conn = app(\PDO::class);
        $stmt = $conn->prepare("insert into restrictions values(?, ?) on duplicate key update end_date=?");
        $stmt->execute(array($request->user_id, $end_date, $end_date));
        $result = $stmt->rowCount();

        return response()->json([
            'status' => true,
            'message' => "User successfully restricted"
        ]);
    }

    public function activate_user(Request $request){
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
        ]);

        if($validator->fails()){
            return response()->json([
                "status" => false,
                "errors" => $validator->errors()
            ], 422);
        }

        $conn = app(\PDO::class);
        $stmt = $conn->prepare("insert into restrictions values(?, null) on duplicate key update end_date=null");
        $stmt->execute(array($request->user_id));
        $result = $stmt->rowCount();

        return response()->json([
            'status' => true,
            'message' => "User successfully activated"
        ]);
    }

    public function logout(Request $request){
        $token = $request->header("Authorization");

        $conn = app(\PDO::class);
        $stmt = $conn->prepare("update users set token=null where users.token=?");
        $stmt->execute(array($token));
        $result = $stmt->rowCount();

        if($result == 1){
            return response()->json([
                'status' => true,
                'message' => "User successfully logged out"
            ]);
        }
        else{
            return response()->json([
                'status' => false,
                'errors' => ["Could not log out a user"]
            ]);
        }
    }
}
