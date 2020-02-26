<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserUpdate;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Ticket;
use App\Utils\Helper;
use App\Models\Order;
use App\Models\ServerLog;

class UserController extends Controller
{
    public function logout(Request $request)
    {
        $request->session()->flush();
        return response([
            'data' => true
        ]);
    }

    public function changePassword(Request $request)
    {
        if (empty($request->input('old_password'))) {
            abort(500, '旧密码不能为空');
        }
        if (empty($request->input('new_password'))) {
            abort(500, '新密码不能为空');
        }
        $user = User::find($request->session()->get('id'));
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $request->input('old_password'),
            $user->password)
        ) {
            abort(500, '旧密码有误');
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        if (!$user->save()) {
            abort(500, '保存失败');
        }
        $request->session()->flush();
        return response([
            'data' => true
        ]);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->session()->get('id'))
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'enable',
                'is_admin',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate'
            ])
            ->first();
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->session()->get('id'))
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->session()->get('id'))
                ->count(),
            User::where('invite_user_id', $request->session()->get('id'))
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::find($request->session()->get('id'));
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, '订阅计划不存在');
            }
        }
        $user['subscribe_url'] = config('v2board.subscribe_url', config('v2board.app_url', env('APP_URL'))) . '/api/v1/client/subscribe?token=' . $user['token'];
        return response([
            'data' => $user
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->session()->get('id'));
        $user->v2ray_uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, '重置失败');
        }
        return response([
            'data' => config('v2board.subscribe_url', config('v2board.app_url', env('APP_URL'))) . '/api/v1/client/subscribe?token=' . $user->token
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->session()->get('id'));
        if (!$user) {
            abort(500, '该用户不存在');
        }
        if (!$user->update($updateData)) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }
}
