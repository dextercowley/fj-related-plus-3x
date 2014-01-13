<?php
/**
 * @version		$Id: helper.php 294 2012-01-05 00:47:00Z dextercowley $
 * @package		mod_fj_related_plus
 * @copyright	Copyright (C) 2008 Mark Dexter. All rights reserved.
 * @license		http://www.gnu.org/licenses/gpl.html
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

require_once (JPATH_SITE .'/components/com_content/helpers/route.php');

class modFJRelatedPlusHelper
{
	/**
	 * The tags from the Main Article
	 *
	 * @access public
	 * @var array  Associative array $tagId => $tagTitle
	 */
	static $mainArticleTags = array();
	static $mainArticleAlias = null;
	static $mainArticleAuthor = null;
	static $mainArticleCategory = null;
	static $includeTagArray = array();

	public static function getList($params)
	{
		$includeMenuTypes = $params->get('fj_menu_item_types', 'article');
		// only do this if this is an article or if we are showing this module for any menu item type
		if (self::isArticle() || ($includeMenuTypes == 'any')) //only show for article pages
		{
			$db	= JFactory::getDBO();
			$user = JFactory::getUser();
			$userGroups = implode(',', $user->getAuthorisedViewLevels());

			$showDate = $params->get('showDate', 'none');
			$showLimit = intval($params->get('count', 5));
			$minimumMatches = intval($params->get('minimumMatches', 1));
			$minimumMatches = ($minimumMatches > 0) ? $minimumMatches : 1;
			$showCount = $params->get('showMatchCount', 0);
			$showMatchList = $params->get('showMatchList', 0);
			$orderBy = $params->get('ordering', 'alpha');

			// process categories either as comma-delimited list or as array
			// (for backward compatibility)
			$catid = (is_array($params->get('catid'))) ?
				implode(',', $params->get('catid') ) : trim($params->get('catid'));

			$matchAuthor = trim($params->get('matchAuthor', 0));
			$matchAuthorCondition = '';
			$matchAuthorAlias = trim($params->get('matchAuthorAlias', 0));
			$matchAuthorAliasCondition = '';
			$matchCategory = $params->get('fjmatchCategory');
			$matchCategoryCondition = '';
			$anyOrAll = $params->get('anyOrAll', 'any');

			$showTooltip = $params->get('show_tooltip', 1);
			$tooltipLimit = (int) $params->get('max_chars', 250);

			$ignoreTags = $params->get('ignore_tags', '');
			$ignoreAllTags = $params->get('ignore_all_tags', 0);
			$includeTags = $params->get('include_tags');

			$includeCategories = (is_array($params->get('fj_include_categories')))
				? implode(',', $params->get('fj_include_categories')) : $params->get('fj_include_categories');
			$includeAuthors	= (is_array($params->get('fj_include_authors')))
				? implode(',', $params->get('fj_include_authors')) : $params->get('fj_include_authors');
			// put quotes around
			$includeAliases	= (is_array($params->get('fj_include_alias')))
				? implode(',', array_map(array('self', 'dbQuote'), $params->get('fj_include_alias')))
				: self::dbQuote($params->get('fj_include_alias'));

			$nullDate = $db->getNullDate();

			$date = JFactory::getDate();
			$now  = $date->toSQL();

			$related = array();
			$matching_tags = array();
			$metakey = '';
			$id = JFactory::getApplication()->input->getInt('id');

			if (self::isArticle()) {
				// select the author info from the item
				$query = 'SELECT a.metakey, a.catid, a.created_by, a.created_by_alias,' .
					' cc.title as category_title, u.name as author ' .
					' FROM #__content AS a' .
					' LEFT JOIN #__categories AS cc ON cc.id = a.catid' .
					' LEFT JOIN #__users AS u ON u.id = a.created_by' .
					' WHERE a.id = '.(int) $id;
				$db->setQuery($query);
				$mainArticle = $db->loadObject();

				// Get tags for this article.
				// Load the tags from the mapping table
				$query = $db->getQuery(true);
				$query->select('t.id, t.title')
					->from('#__tags AS t')
					->innerJoin('#__contentitem_tag_map AS m ON t.id = m.tag_id')
					->where("m.type_alias = 'com_content.article'")
					->where('content_item_id = ' . $id)
					->order('t.title ASC');
				$db->setQuery($query);
				$tagObjects = $db->loadObjectList();
				foreach ($tagObjects as $tagObject)
				{
					self::$mainArticleTags[$tagObject->id] = $tagObject->title;
				}


			}
			else
			{
				// create an empty article object
				$articleArray = array('created_by_alias' =>'', 'author' =>'',
					'category_title' => '', 'metakey' => '', 'catid' => '',
					'created_by' => '');
				$mainArticle = JArrayHelper::toObject($articleArray);
			}
			self::$mainArticleAlias = $mainArticle->created_by_alias;
			self::$mainArticleAuthor = $mainArticle->author;
			self::$mainArticleCategory = $mainArticle->category_title;

			if ((count(self::$mainArticleTags) > 0) || 	// do the query if there are tags
				($matchAuthor) || // or if the author match is on
				// or if the alias match is on and an alias
				(($matchAuthorAlias) && ($mainArticle->created_by_alias)) ||
				($matchCategory) ||	// or if the match category parameter is yes
				($includeCategories > ' ') || // or other categories
				($includeAuthors > ' ') || // or other authors
				($includeAliases > ' ') || // or other author aliases
				($includeTags)) // or include tags
			{
				$query = $db->getQuery(true);
				$selectQuery = $db->getQuery(true); // Second query object to allow any / all / exact

				// get array of tags to ignore
				$ignoreTagArray = array();
				if ($ignoreTags)
				{
					$ignoreTagArray = $ignoreTags;
				}

				if ($includeTags)
				{
					self::$includeTagArray = $includeTags;
				}
				$includeTagCount = count(self::$includeTagArray);

				// Process include_tags

				if ((count(self::$mainArticleTags)) || //the current article has tags
					($matchAuthor) || // or we are matching on author
					(($matchAuthorAlias) && ($mainArticle->created_by_alias)) || // or author alias
					($matchCategory) || // or category
					($includeCategories > ' ') || // or other categories
					($includeAuthors > ' ') || // or other authors
					($includeAliases > ' ')) // or other author aliases
				{
					// get the ordering for the query
					if ($showDate == 'modify')
					{
						$query->select('a.modified as date');
						$dateOrderby = 'a.modified';
					}
					elseif ($showDate == 'published')
					{
						$query->select('a.publish_up as date');
						$dateOrderby = 'a.publish_up';
					}
					else
					{
						$query->select('a.created as date');
						$dateOrderby = 'a.created';
					}

					switch ($orderBy)
					{
						case 'alpha' :
							$query->order('a.title');
							break;

						case 'rdate' :
							$query->order($dateOrderby . ' DESC, a.title ASC');
							break;

						case 'date' :
							$query->order($dateOrderby . ' ASC, a.title ASC');
							break;

						case 'bestmatch' :
							$query->order('match_count DESC');
							break;

						case 'article_order' :
							$query->order('cc.lft ASC, a.ordering ASC, a.title ASC');
							break;

						case 'random' :
							$query->select('rand() as random');
							$query->order('random ASC');
							break;

						default:
							$query->order('a.title ASC');
					}

					if (count(self::$mainArticleTags) > 0)
					{
						// $tagQuery is used to build subquery for getting tag information from the mapping table
						$tagQuery = $db->getQuery(true);
						$tagQuery->from('#__contentitem_tag_map')
							->select('content_item_id')
							->select('COUNT(*) AS total_tag_count')
							->select('SUM(CASE WHEN tag_id IN (' . implode(',', array_keys(self::$mainArticleTags)) . ') THEN 1 ELSE 0 END) AS matching_tag_count')
							->select('GROUP_CONCAT(CASE WHEN tag_id IN (' . implode(',', array_keys(self::$mainArticleTags)) . ') THEN tag_id ELSE null END) AS matching_tags')
							->where('type_alias = \'com_content.article\'')
							->group('content_item_id');
						$tagQueryString = '(' . trim((string) $tagQuery) . ')';
						$query->leftJoin($tagQueryString . ' AS m ON m.content_item_id = a.id');
						$query->select('m.total_tag_count, m.matching_tag_count AS match_count, m.matching_tags as match_list');

						switch ($anyOrAll)
						{
							case 'all':
								$selectQuery->where('m.matching_tag_count = ' . $count, 'OR');
								break;
							case 'exact':
								$selectQuery->where('(m.matching_tag_count = ' . $count . ' AND m.matching_tag_count = m.total_tag_count)', 'OR');
								break;
							default:
								$selectQuery->where('m.matching_tag_count >= ' . $minimumMatches, 'OR');
						}
					}
					else
					{
						$query->select('0 AS total_tag_count, 0 AS match_count, \'\' AS match_list');
					}

					if ($catid > ' ' and ($mainArticle->catid > ' ')) {
						$ids = str_replace('C', $mainArticle->catid, JString::strtoupper($catid));
						$ids = explode( ',', $ids);
						JArrayHelper::toInteger( $ids );
						$query->where('a.catid IN (' . implode(',', $ids ) . ')');
					}

					if ($matchAuthor) {
						$selectQuery->where('a.created_by = ' . $db->quote($mainArticle->created_by), 'OR');
					}

					if (($matchAuthorAlias) && ($mainArticle->created_by_alias)) {
						$selectQuery->where('UPPER(a.created_by_alias) = '
							. $db->Quote(JString::strtoupper($mainArticle->created_by_alias)), 'OR');
					}

					if ($matchCategory) {
						$selectQuery->where('a.catid = ' . $db->quote($mainArticle->catid), 'OR');
					}

					if ($includeCategories > ' ') {
						$selectQuery->where('a.catid in ('. $includeCategories . ')', 'OR');
					}

					if ($includeAuthors > ' ') {
						$selectQuery->where('a.created_by in ('. $includeAuthors . ')', 'OR');
					}

					if ($includeAliases > ' ') {
						$selectQuery->where('a.created_by_alias in ('. $includeAliases . ')', 'OR');
					}

					// select other items based on the metakey field 'like' the keys found
					$query->select('a.id, a.title, a.introtext');
					$query->select('a.catid, cc.access AS cat_access');
					$query->select('a.created_by, a.created_by_alias, u.name AS author');
					$query->select('cc.published AS cat_state');
					$query->select('CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(":", a.id, a.alias) ELSE a.id END as slug');
					$query->select('CASE WHEN CHAR_LENGTH(cc.alias) THEN CONCAT_WS(":", cc.id, cc.alias) ELSE cc.id END as catslug');
					$query->select('cc.title as category_title, a.introtext as introtext_raw, a.fulltext');
					$query->select('a.metakey');
					$query->from('#__content AS a');
					$query->leftJoin('#__content_frontpage AS f ON f.content_id = a.id');
					$query->leftJoin('#__categories AS cc ON cc.id = a.catid');
					$query->leftJoin('#__users AS u ON u.id = a.created_by');
					$query->where('a.id != '.(int) $id);
					$query->where('a.state = 1');
					$query->where('a.access IN (' . $userGroups . ')');
					$query->where('cc.access IN (' . $userGroups . ')');
					$query->where('cc.published = 1');
					$query->where('(a.publish_up = '.$db->Quote($nullDate).' OR a.publish_up <= '.$db->Quote($now).' )');
					$query->where('(a.publish_down = '.$db->Quote($nullDate).' OR a.publish_down >= '.$db->Quote($now).')');

					// Plug in the WHERE clause of $selectQuery inside ()
					$query->where('(' . substr((string) $selectQuery->where, 8) . ')');

					$db->setQuery($query, 0, $showLimit);
					$temp = $db->loadObjectList();
					$related = array();

					if (count($temp) > 0)
					{
						foreach ($temp as $row)
						{
							$row->route = JRoute::_(ContentHelperRoute::getArticleRoute($row->slug, $row->catslug));
							// add processing for intro text tooltip
							if ($showTooltip)
							{
								// limit introtext to length if parameter set & it is needed
								$strippedText = strip_tags($row->introtext);
								$row->introtext = self::fixSefImages($row->introtext);
								if (($tooltipLimit > 0) && (strlen($strippedText) > $tooltipLimit))
								{
									$row->introtext = htmlspecialchars(self::getPreview($row->introtext, $tooltipLimit)) . ' ...';
								}
								else
								{
									$row->introtext = htmlspecialchars($row->introtext);
								}
							}

							// Get list of matching tags
							if ($showMatchList && $row->match_count)
							{
								$tagNameArray = array();
								$tagArray = explode(',', $row->match_list);
								foreach ($tagArray as $tagId)
								{
									$tagNameArray[] = self::$mainArticleTags[$tagId];
								}
								$row->match_list = $tagNameArray;
							}
							$related[] = $row;

						}
					}
				}
			}

			return $related;
		}
	}

	/**
	 * This function returns the text up to the last space in the string.
	 * This is used to always break the introtext at a space (to avoid breaking in
	 * the middle of a special character, for example.
	 * @param $rawText
	 * @return string
	 */
	public static function getUpToLastSpace($rawText)
	{
		$throwAway = strrchr($rawText, ' ');
		$endPosition = strlen($rawText) - strlen($throwAway);
		return substr($rawText, 0, $endPosition);
	}

	/**
	 * Function to extract first n chars of text, ignoring HTML tags.
	 * Text is broken at last space before max chars in stripped text
	 * @param $rawText full text with tags
	 * @param $maxLength max length
	 * @return unknown_type
	 */
	public static function getPreview($rawText, $maxLength) {
		$strippedText = substr(strip_tags($rawText), 0, $maxLength);
		$strippedText = self::getUpToLastSpace($strippedText);
		$j = 0; // counter in $rawText
		// find the position in $rawText corresponding to the end of $strippedText
		for ($i = 0; $i < strlen($strippedText); $i++) {
			// skip chars in $rawText that were stripped
			while (substr($strippedText,$i,1) != substr($rawText, $j,1)) {
				$j++;
			}
			$j++; // we found the next match. now increment to keep in synch with $i
		}
		return (substr($rawText, 0, $j)); // return up to this char
	}

	/**
	 * Function to test whether we are in an article view.
	 *
	 * returns boolean True if current view is an article
	 */
	public static function isArticle() {
		$option = JRequest::getCmd('option');
		$view = JRequest::getCmd('view');
		$id	= JRequest::getInt('id');
		// return True if this is an article
		return ($option == 'com_content' && $view == 'article' && $id);
	}

	/**
	 * Function to fix SEF images in tooltip -- add base to image URL
	 * @param $buffer -- intro text to fix
	 * @return $fixedText -- with image tags fixed for SEF
	 */
	public static function fixSefImages ($buffer) {
		$config = JFactory::getConfig();
		$sef = $config->get('config.sef');
		if ($sef) // process if SEF option enabled
		{
			$base   = JURI::base(true).'/';
			$protocols = '[a-zA-Z0-9]+:'; //To check for all unknown protocals (a protocol must contain at least one alpahnumeric fillowed by :
			$regex     = '#(src|href)="(?!/|'.$protocols.'|\#|\')([^"]*)"#m';
			$buffer    = preg_replace($regex, "$1=\"$base\$2\"", $buffer);
		}
		return $buffer;
	}

	public static function dbQuote($string)
	{
		if ($string)
		{
			$string = JFactory::getDBO()->quote($string);
		}
		return $string;
	}

}
