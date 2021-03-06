<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// $Id: include.inc.php,v 1.23.6 2011/02/05 10:56:00 Logue Exp $
//
// Include-once plugin

//--------
//	| PageA
//	|
//	| // #include(PageB)
//	---------
//		| PageB
//		|
//		| // #include(PageC)
//		---------
//			| PageC
//			|
//		--------- // PageC end
//		|
//		| // #include(PageD)
//		---------
//			| PageD
//			|
//		--------- // PageD end
//		|
//	--------- // PageB end
//	|
//	| #include(): Included already: PageC
//	|
//	| // #include(PageE)
//	---------
//		| PageE
//		|
//	--------- // PageE end
//	|
//	| #include(): Limit exceeded: PageF
//	| // When PLUGIN_INCLUDE_MAX == 4
//	|
//	|
//-------- // PageA end

// ----

use PukiWiki\Factory;
use PukiWiki\Renderer\RendererFactory;
use PukiWiki\Utility;

// Default value of 'title|notitle' option
defined('PLUGIN_INCLUDE_WITH_TITLE') or define('PLUGIN_INCLUDE_WITH_TITLE', TRUE);	// Default: TRUE(title)

// Max pages allowed to be included at a time
defined('PLUGIN_INCLUDE_MAX') or define('PLUGIN_INCLUDE_MAX', 4);

// ----
define('PLUGIN_INCLUDE_USAGE', '#include(): Usage: (a-page-name-you-want-to-include[,title|,notitle])');

function plugin_include_convert()
{
	global $vars, $get, $post, $menubar, $sidebar;
	static $included = array();
	static $count = 1;

	$_msg_include_restrict = T_('Due to the blocking, $1 cannot be include(d).');

	if (func_num_args() == 0){
		return '<p class="alert alert-warning">' .PLUGIN_INCLUDE_USAGE . '</p>' . "\n";
	}

	// $menubar will already be shown via menu plugin
	if (! isset($included[$menubar])) $included[$menubar] = TRUE;

	// Loop yourself
	$root = isset($vars['page']) ? $vars['page'] : '';
	$included[$root] = TRUE;

	// Get arguments
	$args = func_get_args();
	// strip_bracket() is not necessary but compatible
	$page = isset($args[0]) ? array_shift($args) : null;

	$wiki = Factory::Wiki(Utility::getPageName($page, $root));
	
	$with_title = PLUGIN_INCLUDE_WITH_TITLE;
	if (isset($args[0])) {
		switch(strtolower(array_shift($args))) {
		case 'title'  : $with_title = TRUE;  break;
		case 'notitle': $with_title = FALSE; break;
		}
	}
	
	$s_page = Utility::htmlsc($page);

	$link = '<a href="' . $wiki->uri() . '">' . $s_page . '</a>'; // Read link

	// I'm stuffed
	if (isset($included[$page])) {
		return '<p class="alert alert-warning">#include(): Included already: ' . $link . '</p>' . "\n";
	} else if (! is_page($page)) {
		return '<p class="alert alert-warning">#include(): No such page: ' . $s_page . '</p>' . "\n";
	} else if ($count > PLUGIN_INCLUDE_MAX) {
		return '<p class="alert alert-warning">#include(): Limit exceeded: ' . $link . '</p>' . "\n";
	} else {
		++$count;
	}

	// One page, only one time, at a time
	$included[$page] = TRUE;

	// Include A page, that probably includes another pages
	$get['page'] = $post['page'] = $vars['page'] = $page;
	
	if ($wiki->isReadable()) {
		$source = $wiki->get($page);
		preg_replace('/^#navi/','/\/\/#navi/',$source);
		$body = RendererFactory::factory($source);
	} else {
		$body = str_replace('$1', $page, $_msg_include_restrict);
	}
	$get['page'] = $post['page'] = $vars['page'] = $root;

	// Put a title-with-edit-link, before including document
	if ($with_title) {
		$link = '<a href="' . $wiki->uri('edit') . '">' . $s_page . '</a>';
		if ($page == $menubar || $page == $sidebar) {
			$body = '<span align="center"><h5 class="side_label">' .
				$link . '</h5></span><small>' . $body . '</small>';
		} else {
			$body = '<article>'."\n".'<h1>' . $link . '</h1>' . "\n" . $body . "\n".'</article>'."\n";
		}
	}

	return $body;
}
/* End of file include.inc.php */
/* Location: ./wiki-common/plugin/include.inc.php */