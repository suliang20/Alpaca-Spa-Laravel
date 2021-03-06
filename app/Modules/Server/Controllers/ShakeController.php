<?php

namespace App\Modules\Server\Controllers;

use App\Common\Code;
use App\Common\Msg;
use App\Common\Visitor;
use App\Common\Wechat\WeChat;
use App\Models\WsToken;
use App\Modules\Server\Auth\Auth;
use App\Modules\Server\Controllers\Base\BaseController;
use App\Modules\Server\Service\EmailService;
use App\Modules\Server\Service\WxService;
use EasyWeChat\Payment\Order;
use Illuminate\Support\Facades\Log;

class ShakeController extends BaseController
{
    /**
     * 设置不需要登录的的Action
     * @author Chengcheng
     * @date   2016年10月23日 20:39:25
     * @return array
     */
    protected function noLogin()
    {
        return ['index', 'wxLogin', 'testLogin', 'logout', 'getJsPay' . 'wxPayBack'];
    }

    /**
     * 设置不需要权限的的Action
     * @author Chengcheng
     * @date   2016年10月23日 20:39:25
     * @return array
     */
    protected function noAuth()
    {
        // 当前控制器所有方法均不需要权限
        $this->isNoAuth = true;
    }

    /**
     * 用户注销
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return string
     */
    public function logout()
    {
        //注销，清除session
        Auth::auth()->logout();
        $result["code"] = Code::SYSTEM_OK;
        $result["msg"]  = Msg::USER_LOGOUT_OK;
        return $this->ajaxReturn($result);
    }

    /**
     * 登录验证
     * @author Chengcheng
     * @date 2016年10月21日 17:04:44
     * @return bool
     * */
    protected function auth()
    {
        /* 1 获取当前执行的action */
        $action   = $this->getCurrentAction();
        $actionID = $action['method'];

        /* 2 判断Action动作是否需要登录，默认需要登录 */
        $isNeedLogin = true;
        //判断当前控制器是否设置了所有Action动作不需要登录，或者，当前Action动作在不需要登录列表中
        $noLogin = $this->noLogin();
        $noLogin = !empty($noLogin) ? $noLogin : [];
        if (in_array($actionID, $noLogin) || $this->isNoLogin) {
            //不需要登录
            $isNeedLogin = false;
        }

        //* 3 检查用户是否登录-微信openid登录 */
        $wxResult = Auth::auth()->checkLoginWx();
        //如果用户已经微信授权登录，保存用户微信信息，
        if ($wxResult['code'] == Auth::LOGIN_YES) {
            //如果用户已经微信授权登录，设置用wx信息
            Visitor::userWx()->load($wxResult['data']);
        }

        $memberResult = Auth::auth()->checkLoginMember();
        //如果用户已经微信授权登录，保存用户微信信息，
        if ($memberResult['code'] == Auth::LOGIN_YES) {
            //如果用户已经微信授权登录，设置用wx信息
            Visitor::userMember()->load($memberResult['data']);
        }

        // 4 下面分析执行的动作和用户登录行为
        /* 1. 执行动作不需要用户登录,*/
        if ($isNeedLogin == false || $wxResult['code'] == Auth::LOGIN_YES || $memberResult['code'] == Auth::LOGIN_YES) {
            return true;
        }

        // [3] 当前动作需要登录，返回 false,用户未登录，不容许访问
        $result["code"] = Code::USER_LOGIN_NULL;
        $result["msg"]  = Msg::USER_LOGIN_NULL;
        return $result;
    }

    /**
     * 微信登录
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return string
     */
    public function wxLogin()
    {
        //1 获取code
        $this->requestData['code'] = $this->input('code', 0);

        //2 检查code
        if (empty($this->requestData['code'])) {
            $result["code"] = Code::SYSTEM_PARAMETER_NULL;
            $result["msg"]  = sprintf(Msg::SYSTEM_PARAMETER_NULL, 'code');
            return $this->ajaxReturn($result);
        }

        //3 获取信息
        $wxLoginResult = WxService::wxLogin($this->requestData);

        //4 保存登录信息
        if (!empty($wxLoginResult['data']['wx'])) {
            Auth::auth()->loginWx($wxLoginResult['data']['wx']);
            $result["code"] = Code::SYSTEM_OK;
            $result["msg"]  = Msg::SYSTEM_OK;
            return $this->ajaxReturn($result);
        }

        //5 返回结果
        return $this->ajaxReturn($wxLoginResult);
    }

    /**
     * 微信登录
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return string
     */
    public function testLogin()
    {
        //1 获取code
        $this->requestData['name'] = $this->input('testName');

        //2 检查code
        if (empty($this->requestData['name'])) {
            $result["code"] = Code::SYSTEM_PARAMETER_NULL;
            $result["msg"]  = sprintf(Msg::SYSTEM_PARAMETER_NULL, 'test_name');
            return $this->ajaxReturn($result);
        }

        //3 获取信息
        $testLoginResult = WxService::testLogin($this->requestData);

        //4 保存登录信息
        if (!empty($testLoginResult['data'])) {
            Auth::auth()->loginWx($testLoginResult['data']);
            $result["code"] = Code::SYSTEM_OK;
            $result["msg"]  = Msg::SYSTEM_OK;
            return $this->ajaxReturn($result);
        }

        //5 返回结果
        return $this->ajaxReturn($testLoginResult);
    }

    /**
     * 获取token
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return string
     */
    public function getWsToken()
    {
        //获取参数
        $memberId = Visitor::user()->id;
        $type     = $this->input('type', WsToken::MEMBER_TYPE_USER_WX);

        //生成token
        $token = WsToken::model()->generate($memberId, WsToken::MEMBER_TYPE_USER_WX);

        //返回结果
        $result["code"] = Code::SYSTEM_OK;
        $result["msg"]  = Msg::SYSTEM_OK;
        $result["data"] = $token;
        return $this->ajaxReturn($result);
    }

    /**
     * getJsPay 方法 返回支付json串
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return static
     */
    public function getJsPay()
    {
        /* 输入参数  user_id */
        $param['user_id']    = Visitor::user()->id;
        $param['count']      = $this->input('count', 1);
        $param['unit_price'] = 20000;

        $openId  = "ovzxw1rK0KVc725tuYu-wExxafGQ";
        $tradeNo = rand(1000000000, 9000000000);

        /*  生成支付订单，保存到数据库，生成订单no */

        /* 微信支付 统一下单 */
        $payment    = WeChat::app()->payment;
        $attributes = [
            'trade_type'   => 'JSAPI', // JSAPI，NATIVE，APP...
            'body'         => 'iPad mini 16G 白色',
            'detail'       => 'iPad mini 16G 白色',
            'out_trade_no' => $tradeNo,
            'total_fee'    => 2, // 单位：分
            'notify_url'   => 'http://' . $_SERVER['SERVER_NAME'] . '/server/shake/wxPayBack',
            'openid'       => $openId,
        ];

        /* 生成支付JSON串 */
        $resultWx = $payment->prepare(new Order($attributes));

        if ($resultWx->return_code == 'SUCCESS' && $resultWx->result_code == 'SUCCESS') {
            $prepayId       = $resultWx->prepay_id;
            $data           = $payment->configForPayment($prepayId, false);
            $result         = [];
            $result['code'] = Code::SYSTEM_OK;
            $result['msg']  = Msg::SYSTEM_OK;
            $result['data'] = $data;
            return $this->ajaxReturn($result);
        }

        /* 返回结果*/
        $result         = [];
        $result['code'] = Code::SYSTEM_ERROR;
        $result['msg']  = $resultWx->return_msg;
        return $this->ajaxReturn($result);
    }

    /**
     * wxPayBack 微信回调函数
     * @author Chengcheng
     * @date 2016-10-21 09:00:00
     * @return static
     */
    public function wxPayBack()
    {
        $response = WeChat::app()->payment->handleNotify(function ($notify, $successful) {

            $out_trade_no = $notify->out_trade_no;
            Log::info("asdad:." . $out_trade_no);

            return true;
        });
        $response->send();
        exit();
    }

}
