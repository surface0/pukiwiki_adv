<?php
// PukiWiki Plus! - Yet another WikiWikiWeb clone
// $Id: link.php,v 1.20.8 2011/11/28 21:36:00 Logue Exp $
// Copyright (C)
//   2010-2011 PukiWiki Advance Developers Team
//   2005-2007 PukiWiki Plus! Team
//   2003-2007,2011 PukiWiki Developers Team
// License: GPL v2 or (at your option) any later version
//
// Backlinks / AutoLinks related functions

// ------------------------------------------------------------
// DATA STRUCTURE of *.ref and *.rel files

// CACHE_DIR/md5('foobar').ref
// ---------------------------------
// Page-name1<tab>0<\n>
// Page-name2<tab>1<\n>
// ...
// Page-nameN<tab>0<\n>
//
//	0 = Added when link(s) to 'foobar' added clearly at this page
//	1 = Added when the sentence 'foobar' found from the page
//	    by AutoLink feature

// CACHE_DIR/md5('foobar').rel
// ---------------------------------
// Page-name1<tab>Page-name2<tab> ... <tab>Page-nameN
//
//	List of page-names linked from 'foobar'

// ------------------------------------------------------------

// Related cache data extention
defined('PKWK_REL_PREFIX')	or define('PKWK_REL_PREFIX', 'rel-');
// Refered cache data extention
defined('PKWK_REF_PREFIX')	or define('PKWK_REF_PREFIX', 'ref-');

 // Get related-pages from DB
function links_get_related_db($page)
{
	global $cache;
	
	$times = array();
	$ref_name = PKWK_REF_PREFIX.md5($page);
	if (! $cache->hasItem($ref_name)){
		$data = links_update($page);
	}else{
		$data = $cache->getItem($ref_name);
	}

	foreach ($data as $line) {
		$time = get_filetime($line);
		if($time !== 0) $times[$line] = $time;
	}
	// $times['pagename'] = utime;
	
	return $times;
}

// Update link-relationships between pages
function links_update($page)
{
	global $cache;
	// if (PKWK_READONLY) return; // Do nothing
	if (auth::check_role('readonly')) return; // Do nothing

	if (ini_get('safe_mode') == '0') set_time_limit(0);

	$time = is_page($page, TRUE) ? get_filetime($page) : 0;
	$rel_name = PKWK_REL_PREFIX.md5($page);

	if ($cache->hasItem($rel_name)){
		$rel_old = $cache->getItem($rel_name);
		$rel_file_exist = TRUE;
	}else{
		$rel_file_exist = FALSE;
		$rel_old        = array();
	}

	$rel_new  = array();	// Reference to
	$rel_auto = array();	// by AutoLink
	$links    = links_get_objects($page, TRUE);
	foreach ($links as $_obj) {
		if (! isset($_obj->type) || $_obj->type != 'pagename' ||
		    $_obj->name === $page || $_obj->name == '')
			continue;

		if (is_a($_obj, 'Link_autolink')) { // Not cool though
			$rel_auto[] = $_obj->name;
		} else if (is_a($_obj, 'Link_autoalias')) {
			$_alias = get_autoaliases($_obj->name);
			if (is_pagename($_alias)) {
				$rel_auto[] = $_alias;
			}
		} else {
			$rel_new[]  = $_obj->name;
		}
	}
	// 重複を削除
	$rel_new = array_unique($rel_new);
	
	// All pages "Referenced to" only by AutoLink
	$rel_auto = array_diff(array_unique($rel_auto), $rel_new);

	// All pages "Referenced to"
	$rel_new = array_merge($rel_new, $rel_auto);

	// update Pages referred from the $page
	if ($time) {
		// Page exists
		$cache->setItem($rel_name, $rel_new);
	}

	// .ref: Pages refer to the $page
	links_add($page, array_diff($rel_new, $rel_old), $rel_auto);
	links_delete($page, array_diff($rel_old, $rel_new));

	global $WikiName, $autolink, $nowikiname, $search_non_list;

	// $page seems newly created, and matches with AutoLink
	if ($time && ! $rel_file_exist && $autolink
		&& (preg_match("/^$WikiName$/", $page) ? $nowikiname : strlen($page) >= $autolink))
	{
		// Update all, because they __MAY__ refer the $page [HEAVY]
		$search_non_list = 1;
		$pages           = do_search($page, 'AND', TRUE);
		foreach ($pages as $_page) {
			if ($_page !== $page)
				links_update($_page);
		}
	}

	$ref_name = PKWK_REF_PREFIX.md5($page);
	$data = $cache->getItem($ref_name);
	if (! $time && $data) {
		foreach($data as $ref_page=>$ref_auto){
			// Update pages they refer the $page by AutoLink only [HEAVY]
			if ($ref_auto) {
				links_delete($ref_page, array($page));
			}
		}
	}
	return $rel_new;
}

// Init link cache (Called from link plugin)
function links_init()
{
	global $cache;
	// if (PKWK_READONLY) return; // Do nothing
	if (auth::check_role('readonly')) return; // Do nothing

	if (ini_get('safe_mode') == '0') set_time_limit(0);

	// Init database
	$cache->clearByPrefix(PKWK_REL_PREFIX);
	$cache->clearByPrefix(PKWK_REF_PREFIX);

	$ref   = array(); // Reference from
	foreach (get_existpages() as $page) {
		if (is_cantedit($page)) continue;

		$rel   = array(); // Reference to
		$links = links_get_objects($page);
		foreach ($links as $_obj) {
			if (! isset($_obj->type) || $_obj->type != 'pagename' ||
			    $_obj->name == $page || $_obj->name == '')
				continue;

			$_name = $_obj->name;
			if (is_a($_obj, 'Link_autoalias')) {
				$_alias = get_autoaliases($_name);
				if (! is_pagename($_alias))
					continue;	// not PageName
				$_name = $_alias;
			}
			$rel[] = $_name;
			if (! isset($ref[$_name][$page]))
				$ref[$_name][$page] = 1;
			if (! is_a($_obj, 'Link_autolink'))
				$ref[$_name][$page] = 0;
		}
		$rel = array_unique($rel);
		
		if (! empty($rel)) {
			$cache->setItem(PKWK_REL_PREFIX.md5($page), $rel);
		}
	}

	$cache->setItem(PKWK_REF_PREFIX.md5($page), $ref);
}

function links_add($page, $add, $rel_auto)
{
	global $cache;
	// if (PKWK_READONLY) return; // Do nothing
	if (auth::check_role('readonly')) return; // Do nothing

	$rel_auto = array_flip($rel_auto);

	foreach ($add as $_page) {
		$all_auto = isset($rel_auto[$_page]);
		$is_page  = is_page($_page);
		$ref_name = PKWK_REF_PREFIX.md5($_page);

		$data = $cache->getItem($ref_name);
		$ref[] = array($page, $all_auto);
		if ($data !== null){
			foreach ($data as $line) {
				if ($line[0] !== $page) $ref[] = array($line[0], $line[1]);
			}
		}

		if ($is_page || ! $all_auto || count($ref) !== 0) {
			$cache->setItem($ref_name, @array_unique($ref));
		}else{
			$cache->removeItem($ref_name);
		}
		unset($data, $ref);

	}
}

function links_delete($page, $del)
{
	global $cache;
	// if (PKWK_READONLY) return; // Do nothing
	if (auth::check_role('readonly')) return; // Do nothing

	foreach ($del as $_page) {
		$all_auto = TRUE;
		$is_page = is_page($_page);
		
		$ref_name = PKWK_REF_PREFIX.md5($_page);
		$data = $cache->getItem($ref_name);
		if ($data === null) continue;

		$ref = array();
		foreach ($data as $line) {
			list($ref_page, $ref_auto) = $line;
			if ($ref_page !== $page) {
				$ref[] = array($ref_page, $ref_auto);
			}
		}
		if ($is_page || ! $all_auto || count($ref) == 1) {
			$cache->setItem($ref_name, @array_unique($ref));
		}else{
			$cache->removeItem($ref_name);
		}
		unset($data, $ref);
	}
}

function links_get_objects($page, $refresh = FALSE)
{
	static $obj;

	if (! isset($obj) || $refresh)
		$obj = new InlineConverter(NULL, array('note'));

	$result = $obj->get_objects(join('', preg_grep('/^(?!\/\/|\s)./', get_source($page))), $page);
	return $result;
}

/* End of file link.php */
/* Location: ./wiki-common/lib/link.php */