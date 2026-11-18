<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login')->middleware('account.activity:login');
            $router->post('/auth/adminLogin', 'V1\\Passport\\AuthController@adminLogin')->middleware('account.activity:login');
            $router->post('/auth/twoFactor/enroll', 'V1\\Passport\\AuthController@enrollTwoFactor')->middleware('account.activity:login');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login')->middleware('account.activity:login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}
