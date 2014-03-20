<?php
/**
 * PukiPlus ログインプラグイン
 *
 * @copyright   Copyright &copy; 2004-2010, Katsumi Saito <katsumi@jo1upk.ymt.prug.or.jp>
 * @version     $Id: login.php,v 0.23 2012/04/10 18:02:00 Logue Exp $
 * @license     http://opensource.org/licenses/gpl-license.php GNU Public License (GPL2)
 */
// defined('LOGIN_USE_AUTH_DEFAULT') or define('LOGIN_USE_AUTH_DEFAULT', 1);

use PukiWiki\Auth\Auth;
use PukiWiki\Renderer\RendererFactory;
use PukiWiki\Renderer\PluginRenderer;
use PukiWiki\Utility;
use PukiWiki\File\LogFactory;
use PukiWiki\Factory;
/*
 * 初期処理
 */
function plugin_login_init()
{
	$messages = array(
	'_login_msg' => array(
		'msg_username'		=> T_('UserName'),
		'msg_auth_guide'	=> T_('Please attest it with %s to write the comment.'),
		'btn_login'			=> T_('Login'),
		'btn_logout'		=> T_('Logout'),
		'err_notusable'		=>
			'<p class="alert alert-warning">' .
			T_('#login() : Could not use auth function. Please check <var>auth_api.ini.php</var> setting.').
			'</p>',
		'err_auth'			=> T_('Authorization Required'),
		'err_auth_guide'	=>
			'<p class="alert alert-danger"><span class="fa fa-ban"></span>' .
			T_('This server could not verify that you are authorized to access the document requested. Either you supplied the wrong credentials (e.g., bad password), or your browser doesn\'t understand how to supply the credentials required.') .
			'</p>'
		)
	);
	set_plugin_messages($messages);
}

/*
 * ブロック型プラグイン
 */
function plugin_login_convert()
{
	global $vars, $auth_api, $_login_msg;

	@list($type) = func_get_args();

	$auth_key = Auth::get_user_info();

	// LOGIN
	if (!empty($auth_key['key'])) {
		if (isset($auth_api[$auth_key['api']]['hidden_login']) && $auth_api[$auth_key['api']]['hidden_login']) {
			return  $_login_msg['err_notusable'] ;
		}

		if ($auth_key['api'] == 'plus') {
			return <<<EOD
<div>
        <label>{$_login_msg['msg_username']}</label>:
        {$auth_key['key']}
</div>

EOD;
		}
		if (PluginRenderer::hasPlugin($auth_key['api'])) {
			return PluginRenderer::executePluginBlock($auth_key['api']);
		}
		return $_login_msg['err_notusable'];
	}

	$ret = array();

	$ret[] = '<form action="' . get_script_uri() . '" method="post">';
	$ret[] = '<input type="hidden" name="cmd" value="login" />';
	$ret[] = (isset($type)) ? '<input type="hidden" name="type" value="' . Utility::htmlsc($type, ENT_QUOTES) . '" />' : null;
	$ret[] = (isset($vars['page'])) ? '<input type="hidden" name="type" value="' . $vars['page'] . '" />' : null;
	$ret[] = '<div class="login_form">';
	$select = '';
	//if (LOGIN_USE_AUTH_DEFAULT) {
	//	$select .= '<option value="plus" selected="selected">Normal</option>';
	//}
	$sw_ext_auth = false;
	foreach($auth_api as $api=>$val) {
		if (! $val['use']) continue;
		if (isset($val['hidden']) && $val['hidden']) continue;
		if (isset($val['hidden_login']) && $val['hidden_login']) continue;
		$displayname = (isset($val['displayname'])) ? $val['displayname'] : $api;
		if ($api !== 'plus') $sw_ext_auth = true;
		$select .= '<option value="'.$api.'">'.$displayname.'</option>'."\n";
	}

	if (empty($select)) return $_login_msg['err_notusable']; // 認証機能が使えない

	if ($sw_ext_auth) {
		// 外部認証がある
		$ret[] = '<select name="api">'. "\n" .$select.'</select>';
	} else {
		// 通常認証のみなのでボタン
		$ret[] = '<input type="hidden" name="api" value="plus" />';
	}
	$ret[] = '<button type="submit" class="btn btn-success" /><span class="fa fa-power-off"></span>' . $_login_msg['btn_login'] . '</button>';
	$ret[] = '</div>';
	$ret[] = '</form>';
	return join("\n",$ret);
}

function plugin_login_inline()
{
	if (PKWK_READONLY != Auth::ROLE_AUTH) return '';

	$auth_key = Auth::get_user_info();
	

	// Offline
	if (empty($auth_key['key'])) {
		return plugin_login_auth_guide();
	}

	// Online
	return PluginRenderer::hasPlugin($auth_key['api']) ? PluginRenderer::executePluginInline($auth_key['api']) : '';
}

function plugin_login_auth_guide()
{
	global $auth_api,$_login_msg;

	$inline = '';
	$sw = true;
	foreach($auth_api as $api=>$val) {
		if ($val['use']) {
			if (isset($val['hidden']) && $val['hidden']) continue;
			if (! PluginRenderer::hasPlugin($api)) continue;
			$inline .= ($sw) ? '' : ',';
			$sw = false;
			$inline .= '&'.$api.'();';
		}
	}

	if ($sw) return '';
	return RendererFactory::factory(sprintf($_login_msg['msg_auth_guide'],$inline));
}

/*
 * アクションプラグイン
 */
function plugin_login_action()
{
	global $vars, $_login_msg, $defaultpage;

	$api = isset($vars['api']) ? $vars['api'] : 'plus';
	$page = isset($vars['page']) ? $vars['page'] : $defaultpage;

	if ($api !== 'plus') {
		if (! PluginRenderer::hasPlugin($vars['api'])) return;
		$call_api = 'plugin_'.$vars['api'].'_jump_url';
		Utility::redirect( $call_api());
		exit();
	}

	$auth = Auth::authenticate();
	if ($auth === true) {
		// ログイン成功
		LogFactory::factory('login')->set();
		Utility::redirect(Factory::Wiki($page)->uri());
		exit();
	}
	return array(
		'msg'=>$_login_msg['err_auth'],
		'body'=>$_login_msg['err_auth_guide'],
		'http_code'=>401
	);
}


/* End of file login.inc.php */
/* Location: ./wiki-common/plugin/login.inc.php */
