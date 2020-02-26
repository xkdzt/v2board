<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Notice;
use App\Utils\Helper;

class AppController extends Controller
{
    CONST CLIENT_CONFIG = '{"policy":{"levels":{"0":{"uplinkOnly":0}}},"dns":{"servers":["114.114.114.114","8.8.8.8"]},"outboundDetour":[{"protocol":"freedom","tag":"direct","settings":{}}],"inbound":{"listen":"0.0.0.0","port":31211,"protocol":"socks","settings":{"auth":"noauth","udp":true,"ip":"127.0.0.1"}},"inboundDetour":[{"listen":"0.0.0.0","allocate":{"strategy":"always","refresh":5,"concurrency":3},"port":31210,"protocol":"http","tag":"httpDetour","domainOverride":["http","tls"],"streamSettings":{},"settings":{"timeout":0}}],"routing":{"strategy":"rules","settings":{"domainStrategy":"IPIfNonMatch","rules":[{"type":"field","ip":["geoip:cn"],"outboundTag":"direct"},{"type":"field","ip":["0.0.0.0/8","10.0.0.0/8","100.64.0.0/10","127.0.0.0/8","169.254.0.0/16","172.16.0.0/12","192.0.0.0/24","192.0.2.0/24","192.168.0.0/16","198.18.0.0/15","198.51.100.0/24","203.0.113.0/24","::1/128","fc00::/7","fe80::/10"],"outboundTag":"direct"}]}},"outbound":{"tag":"proxy","sendThrough":"0.0.0.0","mux":{"enabled":false,"concurrency":8},"protocol":"vmess","settings":{"vnext":[{"address":"server","port":443,"users":[{"id":"uuid","alterId":2,"security":"auto","level":0}],"remark":"remark"}]},"streamSettings":{"network":"tcp","tcpSettings":{"header":{"type":"none"}},"security":"none","tlsSettings":{"allowInsecure":true,"allowInsecureCiphers":true},"kcpSettings":{"header":{"type":"none"},"mtu":1350,"congestion":false,"tti":20,"uplinkCapacity":5,"writeBufferSize":1,"readBufferSize":1,"downlinkCapacity":20},"wsSettings":{"path":"","headers":{"Host":"server.cc"}}}}}';
    CONST SOCKS_PORT = 10010;
    CONST HTTP_PORT = 10011;

    // TODO: 1.1.1 abolish
    public function data(Request $request)
    {
        $user = $request->user;
        $nodes = [];
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, '订阅计划不存在');
            }
            if ($user->expired_at > time()) {
                $servers = Server::where('show', 1)
                    ->orderBy('name')
                    ->get();
                foreach ($servers as $item) {
                    $groupId = json_decode($item['group_id']);
                    if (in_array($user->group_id, $groupId)) {
                        array_push($nodes, $item);
                    }
                }
            }
        }
        return response([
            'data' => [
                'nodes' => $nodes,
                'u' => $user->u,
                'd' => $user->d,
                'transfer_enable' => $user->transfer_enable,
                'expired_at' => $user->expired_at,
                'plan' => isset($user['plan']) ? $user['plan'] : false,
                'notice' => Notice::orderBy('created_at', 'DESC')->first()
            ]
        ]);
    }

    public function config(Request $request)
    {
        if (empty($request->input('server_id'))) {
            abort(500, '参数错误');
        }
        $user = $request->user;
        if ($user->expired_at < time()) {
            abort(500, '订阅计划已过期');
        }
        $server = Server::where('show', 1)
            ->where('id', $request->input('server_id'))
            ->first();
        if (!$server) {
            abort(500, '服务器不存在');
        }
        $json = json_decode(self::CLIENT_CONFIG);
        //socks
        $json->inbound->port = (int)self::SOCKS_PORT;
        //http
        $json->inboundDetour[0]->port = (int)self::HTTP_PORT;
        //other
        $json->outbound->settings->vnext[0]->address = (string)$server->host;
        $json->outbound->settings->vnext[0]->port = (int)$server->port;
        $json->outbound->settings->vnext[0]->users[0]->id = (string)$user->v2ray_uuid;
        $json->outbound->settings->vnext[0]->users[0]->alterId = (int)$user->v2ray_alter_id;
        $json->outbound->settings->vnext[0]->remark = (string)$server->name;
        $json->outbound->streamSettings->network = $server->network;
        if ($server->settings) {
            switch ($server->network) {
                case 'tcp':
                    $json->outbound->streamSettings->tcpSettings = json_decode($server->settings);
                    break;
                case 'kcp':
                    $json->outbound->streamSettings->kcpSettings = json_decode($server->settings);
                    break;
                case 'ws':
                    $json->outbound->streamSettings->wsSettings = json_decode($server->settings);
                    break;
                case 'http':
                    $json->outbound->streamSettings->httpSettings = json_decode($server->settings);
                    break;
                case 'domainsocket':
                    $json->outbound->streamSettings->dsSettings = json_decode($server->settings);
                    break;
                case 'quic':
                    $json->outbound->streamSettings->quicSettings = json_decode($server->settings);
                    break;
            }
        }
        if ($request->input('is_global')) {
            $json->routing->settings->rules[0]->outboundTag = 'proxy';
        }
        if ($server->tls) {
            $json->outbound->streamSettings->security = "tls";
        }
        die(json_encode($json, JSON_UNESCAPED_UNICODE));
    }
}
