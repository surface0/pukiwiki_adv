<?php
/**
 * PukiWiki Plus! OpenID 認証処理
 *
 * @copyright   Copyright &copy; 2007-2009, Katsumi Saito <katsumi@jo1upk.ymt.prug.or.jp>
 * @author      Katsumi Saito <katsumi@jo1upk.ymt.prug.or.jp>
 * @version     $Id: openid.inc.php,v 0.15.2 2012/05/11 18:27:00 Logue Exp $
 * @license     http://opensource.org/licenses/gpl-license.php GNU Public License (GPL2)
 */
use PukiWiki\Auth\Auth;
use PukiWiki\Auth\AuthOpenId;
use PukiWiki\Auth\AuthOpenIdVerify;
use PukiWiki\Utility;

defined('PLUGIN_OPENID_SIZE_LOGIN')  or define('PLUGIN_OPENID_SIZE_LOGIN', 30);
defined('PLUGIN_OPENID_STORE_PATH')  or define('PLUGIN_OPENID_STORE_PATH', '/tmp/_php_openid_plus');
defined('PLUGIN_OPENID_NO_NICKNAME') or define('PLUGIN_OPENID_NO_NICKNAME', 0); // anonymouse

function plugin_openid_init()
{
	$msg = array(
		'_openid_msg' => array(
			'msg_logout'			=> T_("logout"),
			'msg_logined'			=> T_("%s has been approved by openid."),
			'msg_invalid'			=> T_("The function of opeind is invalid."),
			'msg_not_found'			=> T_("pkwk_session_start() doesn't exist."),
			'msg_not_start'			=> T_("The session is not start."),
			'msg_openid'			=> T_("OpenID"),
			'msg_openid_url'		=> T_("OpenID URL:"),
			'msg_anonymouse'		=> T_("anonymouse"),
			'btn_login'				=> T_("LOGIN"),
			'msg_title'				=> T_("OpenID login form."),
			'err_store_path'		=> T_("Could not create the FileStore directory %s. Please check the effective permissions."),
			'err_cancel'			=> T_("Verification cancelled."),
			'err_failure'			=> T_("OpenID authentication failed: "),
			'err_nickname'			=> T_("nickname must be set."),
			'err_authentication'	=> T_("Authentication error; not a valid OpenID."),
			'err_redirect'			=> T_("Could not redirect to server: %s"),
		)
	);
	set_plugin_messages($msg);
}

function plugin_openid_convert()
{
	global $vars, $auth_api, $_openid_msg;

	if (! isset($auth_api['openid']['use'])) return '';
	if (! $auth_api['openid']['use']) return '<p>'.$_openid_msg['msg_invalid'].'</p>';

	$label  = 'OpenID:';
	$logout = $_openid_msg['msg_logout'];
	$msg = plugin_openid_logoff_msg();
	if ($msg === false) return ''; // 他認証
	if (!empty($msg)) return $msg; // ログオン済

	return plugin_openid_login_form();
}

function plugin_openid_logoff_msg($author='openid',$label='OpenID:',$logout_msg='logout')
{
	global $vars;

	// 処理済みか？
	$obj = new AuthOpenId();
	$name = $obj->getSession();

	if (! empty($name['api'])) {
		switch ($name['api']) {
		case 'openid':
			break; // 認証
		case 'openid_verify':
			// ゴミセッションのため削除
			$obj->unsetSession();
			return ''; // 未認証
		default:
			return false; // 他で認証済
		}
	}

	if (! empty($name['author']) && $name['author'] !== $author) return false;
	if (! empty($name['nickname'])) {
		$display_name = '<a href="'.$name['local_id'].'">'.$name['nickname'].'</a>';
		$page = (empty($vars['page'])) ? '' : $vars['page'];
		$logout_url = get_cmd_uri('openid',$page).'&amp;logout';
		return <<<EOD
<div>
	<label>$label</label>
	$display_name
	(<a href="$logout_url">$logout_msg</a>)
</div>

EOD;
	}
	return '';
}

function plugin_openid_inline()
{
	global $vars,$auth_api,$_openid_msg;

	if (! isset($auth_api['openid']['use'])) return '';
	if (! $auth_api['openid']['use']) return $_openid_msg['msg_invalid'];

	if (! function_exists('pkwk_session_start')) return $_openid_msg['msg_not_found'];

	$obj = new AuthOpenId();
	$name = $obj->getSession();

	if (!empty($name['api']) && $obj->auth_name !== $name['api']) return;

	$page = (empty($vars['page'])) ? '' : $vars['page'];
	$cmd = get_cmd_uri('openid', $page);

	if (! empty($name['nickname'])) {
		if (empty($name['local_id'])) {
			$link = $name['nickname'];
		} else {
			$link = '<a href="'.$name['local_id'].'">'.$name['nickname'].'</a>';
		}
		return sprintf($_openid_msg['msg_logined'],$link) .
			'(<a href="'.$cmd.'&amp;logout'.'">'.$_openid_msg['msg_logout'].'</a>)';
	}

	 $auth_key = Auth::get_user_name();
	if (! empty($auth_key['nick'])) return $_openid_msg['msg_openid'];

	return '<a href="'.$cmd.'">'.$_openid_msg['msg_openid'].'</a>';
}

function plugin_openid_action()
{
	global $vars,$_openid_msg,$auth_api;

	// OpenID 関連プラグイン経由の認証がＯＫの場合のみ通過を許可
	if (!isset($auth_api['openid']['use'])) return '';
	if (! $auth_api['openid']['use']) Utility::dieMessage( $_openid_msg['msg_invalid'] );

	// LOGOUT
	if (isset($vars['logout'])) {
		$obj = new AuthOpenId();
		$obj->unsetSession();
		$page = (empty($vars['page'])) ? '' : $vars['page'];
		Utility::redirect(get_page_location_uri($page));
		die();
	}

	// LOGIN
	if (! isset($vars['action'])) {
		return array('msg'=>$_openid_msg['msg_title'], 'body'=>plugin_openid_login_form() );
	}

	// AUTH
	if (!file_exists(PLUGIN_OPENID_STORE_PATH) && !mkdir(PLUGIN_OPENID_STORE_PATH)) {
		Utility::dieMessage( sprintf($_openid_msg['err_store_path'],PLUGIN_OPENID_STORE_PATH) );
	}

	ini_set('include_path', LIB_DIR . 'openid/');
	require_once('Auth/OpenID/Consumer.php');
	require_once('Auth/OpenID/FileStore.php');
	require_once('Auth/OpenID/SReg.php');
	require_once('Auth/OpenID/PAPE.php');
	ini_restore('include_path');

	global $pape_policy_uris;
	$pape_policy_uris = array(
		PAPE_AUTH_MULTI_FACTOR_PHYSICAL,
		PAPE_AUTH_MULTI_FACTOR,
		PAPE_AUTH_PHISHING_RESISTANT
	);

	$store = new Auth_OpenID_FileStore(PLUGIN_OPENID_STORE_PATH);
	$consumer = new Auth_OpenID_Consumer($store);

	switch($vars['action']) {
	case 'verify':
		if (empty($vars['openid_url'])) {
			return array('msg'=>$_openid_msg['msg_title'], 'body'=>plugin_openid_login_form() );
		}
		return plugin_openid_verify($consumer);
	case 'finish_auth':
		return plugin_openid_finish_auth($consumer);
	}

	// Error.
	Utility::redirect(get_location_uri());
}

function plugin_openid_login_form()
{
	global $vars,$_openid_msg;

	$r_page = (empty($vars['page'])) ? '' : rawurlencode($vars['page']);
	$size = PLUGIN_OPENID_SIZE_LOGIN;

	$script = get_script_uri();
	$rc = <<<EOD
<form method="get" action="$script" class="form-inline plugin-openid-form">
	<input type="hidden" name="cmd" value="openid" />
	<input type="hidden" name="action" value="verify" />
	<input type="hidden" name="page" value="$r_page" />
	{$_openid_msg['msg_openid_url']}
	<input type="text" name="openid_url" size="$size" style="background: url(http://www.openid.net/login-bg.gif) no-repeat; padding-left:18px;" value="" />
	<input type="submit" class="btn btn-success" value="{$_openid_msg['btn_login']}" />
</form>

EOD;
	return $rc;
}

function plugin_openid_verify($consumer)
{
	global $vars,$_openid_msg;

	$page = (empty($vars['page'])) ? '' : ''.$vars['page'];
	$openid = $vars['openid_url'];
	$return_to = get_location_uri('openid','','action=finish_auth');
	$trust_root = get_script_absuri();

	// FIXME: 不正な文字列の場合は、logoff メッセージを設定できない
	$author = (empty($vars['author'])) ? 'openid' : $vars['author'];

	$auth_request = $consumer->begin($openid);
	if (!$auth_request) {
		Utility::dieMessage( $_openid_msg['err_authentication'] );
	}

	$sreg_request = Auth_OpenID_SRegRequest::build(
					// Required
					array('nickname'),
					// Optional
					array('fullname', 'email'));
	if ($sreg_request) {
		$auth_request->addExtension($sreg_request);
	}

	$shouldSendRedirect = $auth_request->shouldSendRedirect();
	if ($shouldSendRedirect) {
		$redirect_url = $auth_request->redirectURL($trust_root, $return_to);
		if (Auth_OpenID::isFailure($redirect_url)) {
			Utility::dieMessage( sprintf($_openid_msg['err_redirect'],$redirect_url->message) );
		}
	} else {
		$form_id = 'openid_message';
		$form_html = $auth_request->htmlMarkup($trust_root, $return_to, false, array('id' => $form_id));
		if (Auth_OpenID::isFailure($form_html)) {
			Utility::dieMessage( sprintf($_openid_msg['err_redirect'],$form_html->message) );
		}
	}

	// v1			v2
	// openid.server	openid2.provider	=> $auth_request->endpoint->server_url	ex. http://www.myopenid.com/server
	// openid.delegate	openid2.local_id	=> $auth_request->endpoint->local_id	ex. http://youraccount.myopenid.com/
	$obj = new auth_openid_plus_verify();
	$obj->response = array( 'server_url' => $auth_request->endpoint->server_url,
		'local_id'   => $auth_request->endpoint->local_id,
		'page'       => $page,
		'author'     => $author
	);
	$obj->setSession();

	if ($shouldSendRedirect) {
		Utility::redirect($redirect_url);
	} else {
		//print $form_html;
		Utility::dieMessage($form_html);
	}
}

function plugin_openid_finish_auth($consumer)
{
	global $vars,$_openid_msg;

	$obj_verify = new AuthOpenIdVerify();
	$session_verify = $obj_verify->getSession();
	//$session_verify['server_url']
	//$session_verify['local_id']
	$page = (empty($session_verify['page'])) ? '' : rawurldecode($session_verify['page']);
	$author = (empty($session_verify['author'])) ? '' : rawurldecode($session_verify['author']);
	$obj_verify->unsetSession();
	$return_to = get_page_location_uri($page);
	$response = $consumer->complete($return_to);

/*
echo '<pre>';
var_dump($response);
die();
*/

	switch($response->status) {
	case Auth_OpenID_CANCEL:
		Utility::dieMessage( $_openid_msg['err_cancel'] );
	case Auth_OpenID_FAILURE:
		Utility::dieMessage( $_openid_msg['err_failure'] . $response->message );
	case Auth_OpenID_SUCCESS:
		$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
		$sreg = $sreg_resp->contents();
		// $sreg['email'], $sreg['nickname'], $sreg['fullname']

		if (! isset($sreg['nickname'])) {
			if (PLUGIN_OPENID_NO_NICKNAME) {
				$sreg['nickname'] = 'anonymouse';
			} else {
				Utility::dieMessage( $_openid_msg['err_nickname'] );
			}
		}

		$obj = new AuthOpenId();
		$obj->response = $sreg; // その他の項目を引き渡す
		$obj->response['author'] = $author;
		$obj->response['local_id'] = (!empty($response->endpoint->local_id)) ? $response->endpoint->local_id : $response->endpoint->claimed_id;
		$obj->response['identity_url'] = $response->getDisplayIdentifier();
		$obj->setSession();
		break;
	}

	// オリジナルの画面に戻る
	header('Location: '. get_page_location_uri($page));
}

function plugin_openid_get_user_name()
{
	global $auth_api;
	// role,name,nick,profile
	if (! $auth_api['openid']['use']) return array('role'=>Auth::ROLE_GUEST,'nick'=>'');
	$obj = new AuthOpenId();
	$msg = $obj->getSession();
	if (empty($msg['nickname'])) return array('role'=>Auth::ROLE_GUEST,'nick'=>'');

	if (empty($msg['local_id'])) {
		$key = '';
		$prof = $msg['nickname'];
	} else {
		$key = $prof = $msg['local_id'];
	}

	$name = plugin_openid_get_call_func($msg['identity_url']);
	if (empty($name) || !exist_plugin($name)) {
		return array('role'=>Auth::ROLE_AUTH_OPENID,'nick'=>$msg['nickname'],'profile'=>$prof,'key'=>$key);
	}

	if (function_exists($name . '_get_user_name')) {
		$aryargs = array($msg,$prof,$key);
		return call_user_func_array($name . '_get_user_name', $aryargs);
	}

	return array('role'=>AuthOpenId::ROLE_AUTH_OPENID,'nick'=>$msg['nickname'],'profile'=>$prof,'key'=>$key);
}

function plugin_openid_jump_url()
{
	global $vars;
	$page = (empty($vars['page'])) ? '' : $vars['page'];
	return get_location_uri('openid',$page);
}

function plugin_openid_get_call_func($openid)
{
	// 今後、OpenID で色々な制限が可能となった場合に、固有判定が行えるような I/F をもっておく
	$sub_api = array(
		'https://id.mixi.jp/'			=> 'auth_mixi',
		'https://openid.excite.co.jp/'	=> 'auth_openid_btn',
	);

	foreach($sub_api as $uri=>$plugin) {
		$chk = strpos($openid, $uri);
		if ($chk === false) continue;
		return $plugin;
	}

	return '';
}

/* End of file openid.inc.php */
/* Location: ./wiki-common/plugin/openid.inc.php */
