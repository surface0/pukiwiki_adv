<?php
// $Id: csv2newpage.inc.php,v 0.14.5 2012/06/08 17:57:00 Logue Exp $

/*
*プラグイン csv2newpage
 CSVファイルからページを新規作成 for PukiWiki Plus! I18N

*Usage
#csv2newpage(tracker_configname,[upload,<start line_no>],[date|_page|name|...])

*引数
  最初の引数は、tracerのconfig名, 次以降はCSVファイルのフィールド順に
  設定したいフィールド名を記載。
*/

use PukiWiki\Auth\Auth;
use PukiWiki\Factory;
use PukiWiki\Config\Config;
use PukiWiki\Utility;
use PukiWiki\Renderer\RendererFactory;
use PukiWiki\Router;

// 管理者だけが添付ファイルをアップロードできるようにする
defined('CSV2NEWPAGE_UPLOAD_ADMIN_ONLY') or define('CSV2NEWPAGE_UPLOAD_ADMIN_ONLY',FALSE); // FALSE or TRUE

// アップロード/削除時にパスワードを要求する(ADMIN_ONLYが優先)
defined('CSV2NEWPAGE_PASSWORD_REQUIRE') or define('CSV2NEWPAGE_PASSWORD_REQUIRE',FALSE); // FALSE or TRUE

// define('TRACKER_LIB', PLUGIN_DIR.'tracker.inc.php');
// define('ATTACH_LIB',  PLUGIN_DIR.'attach.inc.php');

function plugin_csv2newpage_init()
{
	$messages = array(
	  '_csv2newpage_messages' => array(
		 'btn_submit'		=> T_('Exec'),			// 実行
		 'title_text'		=> T_('New page generation:'),	// 新規ページ:
		 'btn_upload'		=> T_('Attache & Exec'),		// 添付＆実行
		 'msg_file'			=> T_('CSV File:'),		// CSVファイル:
		 // attach.inc.php
		 'msg_maxsize'		=> T_('Maximum file size is %s.'),
		 'msg_password'		=> T_('password'),
		 'msg_adminpass'	=> T_('Administrator password'),
      ),
	);
	set_plugin_messages($messages);
}

function plugin_csv2newpage_convert()
{
	global $vars, $_csv2newpage_messages;
	static $numbers = array();

	$page = $vars['page'];

	if (! isset($numbers[$page])) $numbers[$page] = 0;
	$csv2newpage_no = $numbers[$page]++;

	$newpage = '';
	$upload = 0;
	$config_name = 'default';
	$args = func_get_args();

	if ( count($args) == 0 ) return '<p>no option of config_name</p>';
	$config_name = array_shift($args);
	if ( $args[0] == 'upload' ) {
		array_shift($args);
		$upload = 1;
		$start_line_no = array_shift($args);
	}

	if ( count($args) == 0 ) return '<p>no parameter for CSV fields</p>';

	$config = new Config('plugin/tracker/'.$config_name);
	if (!$config->read()) {
		return "<p>config file '".Utility::htmlsc($config_name)."' not found.</p>";
	}
	$config->config_name = $config_name;


	if (! exist_plugin('tracker'))
		return '<p>The tracker plugin is not found.</p>';

	$fields = plugin_tracker_get_fields($page,$page,$config);

	$form = array();
	$ct = 0;

	$form[] = '<input type="hidden" name="cmd" value="csv2newpage" />';
	$form[] = '<input type="hidden" name="_refer" value="' . Utility::htmlsc($page) . '" />';
	$form[] = '<input type="hidden" name="_config" value="' .  Utility::htmlsc($config->config_name) . '" />';

	foreach ( $args as $name ) {
		$ct ++;
		$s_name = Utility::htmlsc($name);
		$form[] = '<input type="hidden" name="csv_field' . $ct . '" value="' . $s_name . '" />'."\n";
	}

	if ( $upload ) {
		$form[] = '<input type="hidden" name="_upload" value="' . $upload . '" />';
		$form[] = '<input type="hidden" name="start_line_no" value="' . $start_line_no . '" />';
		return plugin_csv2newpage_showform(join("\n",$form));
	}

	$ret[] = '<form action="' . Router::get_script_uri() . '" method="post" class="plugin-csv2newpage-form">';
	$ret[] = '<input type="hidden" name="cmd" value="csv2newpage" />';
	$ret[] = '<input type="hidden" name="_refer" value="' . Utility::htmlsc($page) . '" />';
	$ret[] = '<input type="hidden" name="_config" value="' .  Utility::htmlsc($config->config_name) . '" />';
	$ret[] = '<input type="hidden" name="_csv2newpage_no" value="' . $csv2newpage_no . '" />';
	$ret[] = Utility::htmlsc($_csv2newpage_messages['title_text']);
	$ret[] = '<input class="btn btn-primary" type="submit" value="' . Utility::htmlsc($_csv2newpage_messages['btn_submit']) . '" />';
	$ret[] = '</form>';
	return join("\n",$ret);
}

function plugin_csv2newpage_action()
{
	global $vars,$num;

	$config_name = (empty($vars['_config'])) ? '' : $vars['_config'];
	$config = new Config('plugin/tracker/'.$config_name);
	if (!$config->read()) {
		return '<p>config file (' . htmlsc($config_name) .') not found.</p>';
	}
	$config->config_name = $config_name;
	$source = $config->page.'/page';

	$refer = (empty($vars['_refer'])) ? '' : $vars['_refer'];
	if (!is_pagename($refer)) {
		return array(
			'msg'  => 'cannot write',
			'body' => 'page name ('.htmlsc($refer).') is not valid.'
		);
	}
	if (!is_page($source)) {
		return array(
			'msg'  => 'cannot write',
			'body' => 'page template ('.htmlsc($source).') is not exist.'
		);
	}

	$upload =  (empty($vars['_upload'])) ? 0 : $vars['_upload'];
	$csvlines = ( $upload ) ?
		plugin_csv2newpage_upload($refer)
		: plugin_csv2newpage_from_page($refer);

	$csv_fields = plugin_csv2newpage_extract_fields($csvlines);

	// ページデータを生成
	$postdata_template = join('',get_source($source));
	$np = array('*Newpages under [[' . $refer . ']]');
	foreach ( $csv_fields as $csv_field ) {
	    $csv_ct = 1;
	    $ary = array();
	    foreach ( $csv_field as $csv_f ){
			$key = 'csv_field' . $csv_ct;
			if ( ! array_key_exists($key, $vars) ) {
		    		$csv_ct ++;
		    		continue;
			}
			$tracker_key = trim($vars[$key]);
			$ary[$tracker_key] = trim($csv_f);
//			array_push($np, '+' . $tracker_key . ' --- ' . $csv_f);
			$csv_ct ++;
	    }
	    $np_name = plugin_csv2newpage_write($ary,$refer,$postdata_template,$config);
	    $line = join(',',$csv_field);
	    array_push($np, '+' . '[[' . $np_name . ']] ---' . $line);
	}

	return array(
		'msg'  => 'csv2newpage complete',
		'body' => RendererFactory::factory( $np )
	);
}

// Excel2000とほぼ同じ仕様にしよう。
// 行頭または','直後の'"'はquoteモードに移行。quoteモード内の'""'は'"'を意味する。
// 改行も','もquoteされる。quoteモードから出る'"'を見つけると、文字列として出力。
function plugin_csv2newpage_extract_fields($csvlines)
{
	$csv_fields = array();
	$nline = 0;
	$line = '';
	foreach ( $csvlines as $tline ) {
		$line .= $tline;
		for(;;) {
			if ( $line == '' ) { // 行は終了
				$line = '';
				$nline ++;
				break;
			}
			else if ( preg_match('/^([^",][^,]*)?(?:(,)(.*))?$/',$line,$m)) {
				$csv_fields[$nline][] = $m[1];
				if ( $m[2] == '' ) { // 行は終了
					$line = '';
					$nline ++;
					break;
				}
				$line = $m[3];
				continue;
			}
			else if ( preg_match('/^"((?:[^"]|"")*)(?:(")([^,]*))?(?:(,)(.*))?$/s',$line,$m)) {
				if ( $m[2] == '' ) { // ダブルクオーツの中に改行を含む
					$line .= "\n";
					break;
				}
				$csv_fields[$nline][] = str_replace('""','"',$m[1]) . $m[3];
				if ( $m[4] == '' ) { // 行は終了
					$line = '';
					$nline ++;
					break;
				}
				$line = $m[5];
				continue;
			}
		}
	}
	return $csv_fields;
}

function plugin_csv2newpage_from_page($refer)
{
	global $vars;

	$csv2newpage_no = (empty($vars['_csv2newpage_no'])) ? 0 : $vars['_csv2newpage_no'];
	$wiki = Factory::Wiki($refer);
	$postdata = '';
	$csvlines = array();
	$csv2newpage_ct = 0;
	$target_flag = 0;

	foreach ( $wiki->get() as $line ) {
		$found_plugin = preg_match('/^#csv2newpage/',$line);
		if ( $found_plugin and $csv2newpage_ct++ == $csv2newpage_no ) {
			$target_flag = 1;
			$postdata[] = $line;
			continue;
		}
		if ( $target_flag != 1 ) {
			$postdata[] = $line;
			continue;
		}
		$topchar = substr($line,0,1);
		if ( trim($line) == '' || $found_plugin ) {
			$target_flag = 2;
			$postdata[] = $line;
			continue;
		}
		else if (  $topchar != ',' && $topchar != ' ' ){
			$postdata[] = $line;
			continue;
		}
  		$postdata[] = '//' . $line;
		$csvlines[] = substr($line,1);
	}

	// 書き込み
	$wiki->set($postdata);
	return $csvlines;
}

function plugin_csv2newpage_upload($refer)
{
	global $vars;

	$start_line_no = (empty($vars['start_line_no'])) ? 0 : $vars['start_line_no'];
	if ( empty($_FILES['attach_file']) ) {
		return array('msg'=>'no attach_file', 'body'=>'Set attach file' );
	}
	$file = $_FILES['attach_file'];
	$attachname = $file['name'];
	$filename = preg_replace('/\..+$/','', $attachname,1);

	//すでに存在した場合、 ファイル名に'_0','_1',...を付けて回避(姑息)
	$count = '_0';
	while (file_exists(UPLOAD_DIR.encode($refer).'_'.encode($attachname))) {
		$attachname = preg_replace('/^[^\.]+/',$filename.$count++,$file['name']);
	}
	$file['name'] = $attachname;


	if (! exist_plugin('attach'))
		return array('msg'=>'plugin not found', 'body'=> 'The attach plugin is not found.');

	$pass = (empty($vars['pass'])) ? NULL : md5($vars['pass']);
        $retval = attach_upload($file,$refer,$pass);
	if ($retval['result'] != TRUE) {
		return array(
			'msg'  => 'cannot upload',
			'body' => 'cannot upload: '.$attachname.','.$retval
		);
	}
	$realfile = UPLOAD_DIR.encode($refer).'_'.encode($attachname);
	if ( !is_file($realfile)) {
		return array(
			'msg' => 'not found the attached file',
			'body' => "The attached file:'$attachname' does not exist in '$refer'.<br />($realfile)",
		);
	}

	$postdata_old = file($realfile);
	$line = join('', $postdata_old);
	$code = mb_detect_encoding($line);
	$line =	mb_convert_encoding($line, SOURCE_ENCODING, $code);
	$csvlines = preg_split("/\r?\n/",$line);

	if ( $start_line_no ) array_splice($csvlines, 0,$start_line_no);

	return $csvlines;
}

function plugin_csv2newpage_write($ary,$base,$postdata,$config)
{
	global $vars,$now,$num;

	$name = (empty($ary['_name'])) ? '' : $ary['_name'];

	if (! empty($ary['_page'])) {
		$page = $real = $ary['_page'];
		$page = $base.'/'.$page;
	} else {
		$real = is_pagename($name) ? $name : ++$num;
		$page = get_fullname('./'.$real,$base);
	}
	

	if (!Factory::Wiki($page)->isValied()) $page = $base;

	while (Factory::Wiki($page)->isValied()) {
		$real = ++$num;
		$page = $base.'/'.$real;
	}

	// 規定のデータ
	$_post = array_merge($ary,$vars,$_FILES);
	$_post['_date'] = $now;
	$_post['_page'] = $page;
	$_post['_name'] = $name;
	$_post['_real'] = $real;
	// $_post['_refer'] = $_post['refer'];


	if (! exist_plugin('tracker'))
		return array('msg'=>'plugin not found', 'body'=> 'The tracker plugin is not found.');
	$fields = plugin_tracker_get_fields($base,$page,$config);

	foreach ($fields as $key=>$class) {
		if (array_key_exists($key,$_post)) {
			$val = $class->format_value($_post[$key]);
		} else {
			$val = $class->default_value;
		}
		$postdata = str_replace('['.$key.']', $val, $postdata);
	}
	// 書き込み
	Factory::Wiki($page)->set($postdata);
	return $page;
}

//アップロードフォームを表示
function plugin_csv2newpage_showform($retval)
{
	global $_csv2newpage_messages;

	if (! exist_plugin('attach'))
		return array('msg'=>'plugin not found', 'body'=> 'The attach plugin is not found.');

	if (!(bool)ini_get('file_uploads')) return 'file_uploads disabled.';

	$maxsize = MAX_FILESIZE;
	$msg_maxsize = sprintf($_csv2newpage_messages['msg_maxsize'],number_format($maxsize/1000).'KB');

	$pass = '';
	if (CSV2NEWPAGE_PASSWORD_REQUIRE or CSV2NEWPAGE_UPLOAD_ADMIN_ONLY) {
		if (Auth::check_role('role_contents_admin')) {
			$title = $_csv2newpage_messages[CSV2NEWPAGE_UPLOAD_ADMIN_ONLY ? 'msg_adminpass' : 'msg_password'];
			$pass = '<br />'.$title.': <input type="password" name="pass" size="8" />';
		}
	}
	$script = get_script_uri();
	return <<<EOD
<form enctype="multipart/form-data" action="$script" method="post" class="csv2newpage_form">
	<input type="hidden" name="max_file_size" value="$maxsize" />
	<input type="hidden" name="pcmd" value="post" />
	$retval
	<span class="small">$msg_maxsize</span><br />
	{$_csv2newpage_messages['msg_file']} <input type="file" name="attach_file" />
	$pass
	<input type="submit" class="btn btn-primary" value="{$_csv2newpage_messages['btn_upload']}" />
</form>
EOD;
}

/* End of file cvs2newpage.inc.php */
/* Location: ./wiki-common/plugin/cvs2newpage.inc.php */
