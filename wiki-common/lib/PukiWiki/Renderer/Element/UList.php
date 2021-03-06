<?php
/**
 * 箇条書きクラス
 *
 * @package   PukiWiki\Renderer\Element
 * @access    public
 * @author    Logue <logue@hotmail.co.jp>
 * @copyright 2013-2014 PukiWiki Advance Developers Team
 * @create    2013/01/26
 * @license   GPL v2 or (at your option) any later version
 * @version   $Id: UList.php,v 1.0.1 2014/03/17 18:34:00 Logue Exp $
 */

namespace PukiWiki\Renderer\Element;

use PukiWiki\Renderer\Element\ListContainer;

/**
 * - One
 * -- Two
 * --- Three
 */
class UList extends ListContainer
{
	public function __construct(& $root, $text)
	{
		parent::__construct('ul', 'li', '-', $text);
	}
}

/* End of file UList.php */
/* Location: /vendor/PukiWiki/Lib/Renderer/Element/UList.php */