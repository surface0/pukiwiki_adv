<?php
// PukiWiki Advance - Yet another WikiWikiWeb clone.
// $Id: Utility.php,v 1.0.0 2012/12/31 18:18:00 Logue Exp $
// Copyright (C)
//   2012 PukiWiki Advance Developers Team
// License: GPL v2 or (at your option) any later version
namespace PukiWiki\Lib;

class Utility{
	public function __construct(){
	}

	/**
	 * 文字列がURLかをチェック
	 * @param string $str
	 * @param boolean $only_http HTTPプロトコルのみを判定にするか
	 * @return boolean
	 */
	public static function is_uri($str, $only_http = FALSE){
		// URLでありえない文字はfalseを返す
		if ( preg_match( '|[^-/?:#@&=+$,\w.!~*;\'()%]|', $str ) ) {
			return FALSE;
		}

		// 許可するスキーマー
		$scheme = $only_http ? 'https?' : 'https?|ftp|news';

		// URLマッチパターン
		$pattern = (
			'!^(?:'.$scheme.')://'					// scheme
			. '(?:\w+:\w+@)?'						// ( user:pass )?
			. '('
			. '(?:[-_0-9a-z]+\.)+(?:[a-z]+)\.?|'	// ( domain name |
			. '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|'	//   IP Address  |
			. 'localhost'							//   localhost )
			. ')'
			. '(?::\d{1,5})?(?:/|$)!iD'				// ( :Port )?
		);
		// 正規処理
		$ret = preg_match($pattern, $str);
		// マッチしない場合は0が帰るのでFALSEにする
		return ($ret === 0) ? FALSE : $ret;

	}
	/**
	 * InterWikiNameかをチェック
	 * @param string $str
	 * @return boolean
	 */
	public static function is_interwiki($str){
		global $InterWikiName;
		return preg_match('/^' . $InterWikiName . '$/', $str);
	}
	/**
	 * 乱数を生成して暗号化時のsaltを生成する
	 * @param boolean $flush
	 * @return string
	 */
	const TICKET_NAME = 'ticket';
	public static function get_ticket($flush = FALSE)
	{
		global $cache;
		if ($flush) $cache['wiki']->removeItem(self::TICKET_NAME);

		if ($cache['wiki']->hasItem(self::TICKET_CACHE)) {
			$ticket = $cache['wiki']->getItem(self::TICKET_NAME);
		}else{
			$ticket = Zend\Math\Rand::getString(32);
			$cache['wiki']->setItem(self::TICKET_NAME, $ticket);
		}
		return $ticket;
	}
	/**
	 * htmlspacialcharsのエイリアス（PHP5.4対策）
	 * @param string $string 文字列
	 * @param int $flags 変換する文字
	 * @param string $charset エンコード
	 * @return string
	 */
	public static function htmlsc($string = '', $flags = ENT_QUOTES, $charset = 'UTF-8'){
		// Sugar with default settings
		return htmlspecialchars($string, $flags, $charset);	// htmlsc()
	}

	/**
	 * Remove null(\0) bytes from variables
	 * NOTE: PHP had vulnerabilities that opens "hoge.php" via fopen("hoge.php\0.txt") etc.
	 * [PHP-users 12736] null byte attack
	 * http://ns1.php.gr.jp/pipermail/php-users/2003-January/012742.html
	 *
	 * 2003-05-16: magic quotes gpcの復元処理を統合
	 * 2003-05-21: 連想配列のキーはbinary safe
	 *
	 * @param string $param
	 * @return string
	 */
	public static function input_filter($param)
	{
		static $magic_quotes_gpc = NULL;
		if ($magic_quotes_gpc === NULL)
			$magic_quotes_gpc = get_magic_quotes_gpc();

		if (is_array($param)) {
			return array_map('input_filter', $param);
		}
		$result = str_replace('\0', '', $param);
		if ($magic_quotes_gpc) $result = stripslashes($result);
		return $result;
	}
	/**
	 * ページ名をファイル格納用の名前にする（FrontPage→46726F6E7450616765）
	 * @param string $str
	 * @return string
	 */
	public static function encode($str) {
		$value = strval($str);
		return empty($value) ? null : strtoupper(bin2hex($value));
	}
	/**
	 * ファイル格納用の名前からページ名を取得する（46726F6E7450616765→FrontPage）
	 * @param string $str
	 * @return string
	 */
	public static function decode($str) {
		return hex2bin($str);
	}
	/**
	 * ブラケット（[[ ]]）を取り除く
	 * @param string $str
	 * @return string
	 */
	public static function strip_bracket($str)
	{
		$match = array();
		return preg_match('/^\[\[(.*)\]\]$/', $str, $match) ? $match[1] : $str;
	}
	/**
	 * エラーメッセージを表示
	 * @param string $msg エラーメッセージ
	 * @param string $title エラーのタイトル
	 * @param int $http_code 出力するヘッダー
	 */
	public static function die_message($msg, $error_title='', $http_code = 500){
		global $skin_file, $page_title, $_string, $_title, $_button, $vars;

		$title = !empty($error_title) ? $error_title : $_title['error'];
		$page = $_title['error'];

		if (PKWK_WARNING !== true){	// PKWK_WARNINGが有効でない場合は、詳細なエラーを隠す
			$msg = $_string['error_msg'];
		}
		$ret = array();
		$ret[] = '<p>[ ';
		if ( isset($vars['page']) && !empty($vars['page']) ){
			$ret[] = '<a href="' . get_page_location_uri($vars['page']) .'">'.$_button['back'].'</a> | ';
			$ret[] = '<a href="' . self::get_cmd_uri('edit',$vars['page']) . '">Try to edit this page</a> | ';
		}
		$ret[] = '<a href="' . get_cmd_uri() . '">Return to FrontPage</a> ]</p>';
		$ret[] = '<div class="message_box ui-state-error ui-corner-all">';
		$ret[] = '<p style="padding:0 .5em;"><span class="ui-icon ui-icon-alert"></span> <strong>' . $_title['error'] . '</strong> ' . $msg . '</p>';
		$ret[] = '</div>';
		$body = join("\n",$ret);

		global $trackback;
		$trackback = 0;

		if (!headers_sent()){
			pkwk_common_headers(0,0, $http_code);
		}

		if(defined('SKIN_FILE')){
			if (file_exists(SKIN_FILE) && is_readable(SKIN_FILE)) {
				catbody($page, $title, $body);
			} elseif ( !empty($skin_file) && file_exists($skin_file) && is_readable($skin_file)) {
				define('SKIN_FILE', $skin_file);
				catbody($page, $title, $body);
			}
		}else{
			$html = array();
			$html[] = '<!doctype html>';
			$html[] = '<html>';
			$html[] = '<head>';
			$html[] = '<meta charset="utf-8">';
			$html[] = '<meta name="robots" content="NOINDEX,NOFOLLOW" />';
			$html[] = '<link rel="stylesheet" href="http://code.jquery.com/ui/' . JQUERY_UI_VER . '/themes/base/jquery-ui.css" type="text/css" />';
			$html[] = '<title>' . $page . ' - ' . $page_title . '</title>';
			$html[] = '</head>';
			$html[] = '<body>' . $body . '</body>';
			$html[] = '</html>';
			echo join("\n",$html);
		}
		pkwk_common_suffixes();
		die();
	}
	/**
	 * リダイレクト
	 * @param string $url リダイレクト先
	 */
	public static function redirect($url = ''){
		global $vars;
		if (empty($url)){
			$url = isset($vars['page']) ? self::get_page_uri($vars['page']) : self::get_script_uri();
		}
		pkwk_headers_sent();
		header('Status: 301 Moved Permanently');
		header('Location: ' . $url);
		$html = array();
		$html[] = '<!doctype html>';
		$html[] = '<html>';
		$html[] = '<head>';
		$html[] = '<meta charset="utf-8">';
		$html[] = '<meta name="robots" content="NOINDEX,NOFOLLOW" />';
		$html[] = '<meta http-equiv="refresh" content="1; URL='.$url.'" />';
		$html[] = '<link rel="stylesheet" href="http://code.jquery.com/ui/' . JQUERY_UI_VER . '/themes/base/jquery-ui.css" type="text/css" />';
		$html[] = '<title>301 Moved Permanently</title>';
		$html[] = '</head>';
		$html[] = '<body>';
		$html[] = '<div class="message_box ui-state-info ui-corner-all">';
		$html[] = '<p style="padding:0 .5em;"><span class="ui-icon ui-icon-alert"></span>Please click <a href="'.$url.'">here</a> if you do not want to move even after a while.</p>';
		$html[] = '</div>';
		$html[] = '</body>';
		$html[] = '</html>';
		echo join("\n",$html);
		exit;
	}
}

// hex2bin -- Converts the hex representation of data to binary
// (PHP 5.4.0)
// Inversion of bin2hex()
if (! function_exists('hex2bin')) {
	function hex2bin($hex_string) {
		// preg_match : Avoid warning : pack(): Type H: illegal hex digit ...
		// (string)   : Always treat as string (not int etc). See BugTrack2/31
		return preg_match('/^[0-9a-f]+$/i', $hex_string) ?
			pack('H*', (string)$hex_string) : $hex_string;
	}
}

/* End of file Utility.php */
/* Location: /vender/PukiWiki/Lib/Utility.php */