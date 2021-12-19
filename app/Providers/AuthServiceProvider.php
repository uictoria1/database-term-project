<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\GenericUser;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        // Here you may define how you wish users to be authenticated for your Lumen
        // application. The callback which receives the incoming request instance
        // should return either a User instance or null. You're free to obtain
        // the User instance via an API token or any other method necessary.

        $this->app['auth']->viaRequest('api', function ($request) {
            if ($request->hasHeader('Authorization')) {
                try {
                    $conn = app(\PDO::class);
                    $user_stmt = $conn->prepare("select * from users where token=:token limit 1");
                    $user_stmt->execute(array(":token" => $request->header("Authorization")));
                    $user = $user_stmt->fetch(\PDO::FETCH_ASSOC);
                    if(is_bool($user)){
                        return null;
                    }
                    return new GenericUser([
                        "id" => $user['id'],
                        'login' => $user['login']
                    ]);
                } catch (\Throwable $th) {
                    Log::info($th->getMessage());
                    return null;
                }
            }
        });
    }
}
