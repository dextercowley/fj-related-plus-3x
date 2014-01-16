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
	static $mainArticle = null;
	static $includeTagArray = array();
	static $params = null;

	/**
	 * Gets the list of articles for the module
	 *
	 * @param JRegistry  $params  the parameters for the module
	 * @return array              array of row objects for the articles in the module
	 */
	public static function getList($params)
	{
		self::$params = $params;
		$includeMenuTypes = $params->get('fj_menu_item_types', 'article');
		// only do this if this is an article or if we are showing this module for any menu item type
		if (self::isArticle() || ($includeMenuTypes == 'any')) //only show for article pages
		{
			// process categories either as comma-delimited list or as array
			// (for backward compatibility)
			$catid = (is_array($params->get('catid'))) ?
				implode(',', $params->get('catid') ) : trim($params->get('catid'));

			$matchAuthor = trim($params->get('matchAuthor', 0));
			$matchAuthorAlias = trim($params->get('matchAuthorAlias', 0));
			$matchCategory = $params->get('fjmatchCategory');
			$includeTags = $params->get('include_tags');

			$includeCategories = (is_array($params->get('fj_include_categories')))
				? implode(',', array_map('intval', $params->get('fj_include_categories'))) : $params->get('fj_include_categories');

			$includeAuthors	= (is_array($params->get('fj_include_authors')))
				? implode(',', array_map('intval', $params->get('fj_include_authors'))) : $params->get('fj_include_authors');

			$includeAliases	= (is_array($params->get('fj_include_alias')))
				? implode(',', array_map(array('self', 'dbQuote'), $params->get('fj_include_alias')))
				: self::dbQuote($params->get('fj_include_alias'));

			$related = array();
			$matching_tags = array();

			$id = JFactory::getApplication()->input->getInt('id');
			if (self::isArticle())
			{
				self::selectArticle($id);
			}
			else
			{
				// create an empty article object
				$articleArray = array('created_by_alias' =>'', 'author' =>'',
					'category_title' => '', 'metakey' => '', 'catid' => '',
					'created_by' => '');
				self::$mainArticle = JArrayHelper::toObject($articleArray);
			}

			if (self::$params->get('include_tags'))
			{
				self::$includeTagArray = array_map('intval', self::$params->get('include_tags'));
				$includedTagsArray = self::getTagTitles(self::$includeTagArray);
				self::$mainArticleTags = self::$mainArticleTags + $includedTagsArray;
			}

			// If we have tags to exclude, we need to remove them from main article
			// get array of tags to ignore
			if ($params->get('ignore_tags', ''))
			{
				$ignoreTagIds = array_map('intval', self::$params->get('ignore_tags', ''));
				$ignoredTagsArray = self::getTagTitles($ignoreTagIds);
				self::$mainArticleTags = array_diff_assoc(self::$mainArticleTags, $ignoredTagsArray);
			}

			if ((count(self::$mainArticleTags) > 0) || 	// do the query if there are tags
				($matchAuthor) || // or if the author match is on
				// or if the alias match is on and an alias
				(($matchAuthorAlias) && (self::$mainArticle->created_by_alias)) ||
				($matchCategory) ||	// or if the match category parameter is yes
				($includeCategories > ' ') || // or other categories
				($includeAuthors > ' ') || // or other authors
				($includeAliases > ' ') || // or other author aliases
				($includeTags)) // or include tags
			{
				$db	= JFactory::getDBO();
				$query = self::setDateOrderBy(self::$params);

				// At this point, tags have been adjusted for included and ignored tags.
				if (count(self::$mainArticleTags) > 0)
				{
					// $tagQuery is used to build subquery for getting tag information from the mapping table
					$tagQueryString = self::getTagQueryString();
					$query->leftJoin($tagQueryString . ' AS m ON m.content_item_id = a.id');
					$query->select('m.total_tag_count, m.matching_tag_count AS match_count, m.matching_tags as match_list');

					// Second query object to allow any / all / exact
					$selectQuery = self::getSelectQuery($params);
				}
				else
				{
					$query->select('0 AS total_tag_count, 0 AS match_count, \'\' AS match_list');
				}

				// Calculate total matches, including tags and other selections (author, alias, category)
				$totalMatches = '(CASE WHEN m.matching_tag_count IS NULL THEN 0 ELSE m.matching_tag_count END) ';

				if ($catid > ' ' and (self::$mainArticle->catid > ' '))
				{
					$ids = str_replace('C', self::$mainArticle->catid, JString::strtoupper($catid));
					$ids = explode(',', $ids);
					$query->where('a.catid IN (' . implode(',', array_map('intval', $ids)) . ')');
				}

				if ($matchAuthor)
				{
					$selectQuery->where('a.created_by = ' . (int) self::$mainArticle->created_by, 'OR');
					$totalMatches .= ' + (CASE WHEN a.created_by = ' . (int) self::$mainArticle->created_by . ' THEN 1 ELSE 0 END)';
				}

				if (($matchAuthorAlias) && (self::$mainArticle->created_by_alias))
				{
					$selectQuery->where('UPPER(a.created_by_alias) = ' . $db->quote(JString::strtoupper(self::$mainArticle->created_by_alias)), 'OR');
					$totalMatches .= ' + (CASE WHEN UPPER(a.created_by_alias) = ' . strtoupper(self::$mainArticle->author) . ') THEN 1 ELSE 0 END)';
				}

				if ($matchCategory)
				{
					$selectQuery->where('a.catid = ' . $db->quote(self::$mainArticle->catid), 'OR');
					$totalMatches .= ' + (CASE WHEN a.catid = ' . self::$mainArticle->catid . ' THEN 1 ELSE 0 END)';
				}

				if ($includeCategories > ' ')
				{
					$selectQuery->where('a.catid in (' . $includeCategories . ')', 'OR');
					$totalMatches .= ' + (CASE WHEN a.catid IN (' . $includeCategories . ') THEN 1 ELSE 0 END)';
				}

				if ($includeAuthors > ' ')
				{
					$selectQuery->where('a.created_by IN (' . $includeAuthors . ')', 'OR');
					$totalMatches .= ' + (CASE WHEN a.created_by IN (' . $includeAuthors . ') THEN 1 ELSE 0 END)';
				}

				if ($includeAliases > ' ')
				{
					$selectQuery->where('a.created_by_alias in (' . $includeAliases . ')', 'OR');
					$totalMatches .= ' + (CASE WHEN a.created_by_alias IN (' . $includeAliases . ') THEN 1 ELSE 0 END)';
				}

				$query->select($totalMatches . ' AS total_matches');

				// Calculate total_matches including authors, author aliases, current category, and other categories

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
				$query->where('a.id != ' . (int) $id);
				$query->where('a.state = 1');

				$userGroups = implode(',', JFactory::getUser()->getAuthorisedViewLevels());
				$query->where('a.access IN (' . $userGroups . ')');
				$query->where('cc.access IN (' . $userGroups . ')');
				$query->where('cc.published = 1');

				$nullDate = $db->getNullDate();
				$now  = JFactory::getDate()->toSQL();
				$query->where('(a.publish_up = ' . $db->quote($nullDate) . ' OR a.publish_up <= ' . $db->quote($now) . ' )');
				$query->where('(a.publish_down = ' . $db->quote($nullDate) . ' OR a.publish_down >= ' . $db->quote($now) . ')');

				// Plug in the WHERE clause of $selectQuery inside ()
				$query->where('(' . substr((string) $selectQuery->where, 8) . ')');

				$db->setQuery($query, 0, intval($params->get('count', 5)));
				$rows = $db->loadObjectList();

				$related = self::processArticleList($rows);
			}
			return $related;
		}
	}

	/**
	 * Cleans up the intro text if we are using tooltip preview
	 *
	 * @param stdClass  $row  Row object
	 * @return  string  processed introtext string
	 */
	protected static function fixIntroText($row)
	{
		// add processing for intro text tooltip
		if (self::$params->get('show_tooltip', 1))
		{
			// limit introtext to length if parameter set & it is needed
			$strippedText = strip_tags($row->introtext);
			$row->introtext = self::fixSefImages($row->introtext);

			$tooltipLimit = (int) self::$params->get('max_chars', 250);
			if (($tooltipLimit > 0) && (strlen($strippedText) > $tooltipLimit))
			{
				$row->introtext = htmlspecialchars(self::getPreview($row->introtext, $tooltipLimit)) . ' ...';
			}
			else
			{
				$row->introtext = htmlspecialchars($row->introtext);
			}
		}
		return $row->introtext;
	}

	/**
	 * This function returns the text up to the last space in the string.
	 * This is used to always break the introtext at a space (to avoid breaking in
	 * the middle of a special character, for example.
	 *
	 * @param $rawText
	 * @return string
	 */
	protected static function getUpToLastSpace($rawText)
	{
		$throwAway = strrchr($rawText, ' ');
		$endPosition = strlen($rawText) - strlen($throwAway);
		return substr($rawText, 0, $endPosition);
	}

	/**
	 * Function to extract first n chars of text, ignoring HTML tags.
	 * Text is broken at last space before max chars in stripped text
	 *
	 * @param $rawText full text with tags
	 * @param $maxLength max length
	 * @return unknown_type
	 */
	protected static function getPreview($rawText, $maxLength) {
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
	 * Creates the text list of matching tags and other values (author, category, etc.)
	 *
	 * @param stdClass  $row  Row object from the query
	 * @return  string        Processed match list for display
	 */
	protected static function getMatchList($row)
	{
		// Get list of matching tags
		if (self::$params->get('showMatchList', 0) && $row->match_count)
		{
			$tagNameArray = array();
			$tagArray = explode(',', $row->match_list);
			foreach ($tagArray as $tagId)
			{
				$tagNameArray[] = self::$mainArticleTags[$tagId];
			}
			$row->match_list = $tagNameArray;
		}
		return $row->match_list;
	}

	/**
	 * Gets the selection subquery. This allows us to create an OR condition inside the select clause
	 * of the main query.
	 *
	 * @param  JRegistry  $params
	 * @return JDatabaseQuery   Query object for the sub-selection.
	 */
	protected static function getSelectQuery($params)
	{
		$selectQuery = JFactory::getDbo()->getQuery(true);
		switch ($params->get('anyOrAll', 'any'))
		{
			case 'all':
				$selectQuery->where('m.matching_tag_count = ' . $count, 'OR');
				break;
			case 'exact':
				$selectQuery->where('(m.matching_tag_count = ' . $count . ' AND m.matching_tag_count = m.total_tag_count)', 'OR');
				break;
			default:
				$minimumMatches = intval($params->get('minimumMatches', 1));
				$minimumMatches = ($minimumMatches > 0) ? $minimumMatches : 1;
				$selectQuery->where('m.matching_tag_count >= ' . $minimumMatches, 'OR');
		}
		return $selectQuery;
	}

	/**
	 * Creates the subquery for getting the tags for the current article. This is used as a table
	 * in the main query.
	 *
	 * @return string  Subquery text for insertion into the main query as a table.
	 */
	protected static function getTagQueryString()
	{
		$tagQuery = JFactory::getDbo()->getQuery(true);
		$tagQuery->from('#__contentitem_tag_map')
			->select('content_item_id')
			->select('COUNT(*) AS total_tag_count')
			->select('SUM(CASE WHEN tag_id IN (' . implode(',', array_keys(self::$mainArticleTags)) . ') THEN 1 ELSE 0 END) AS matching_tag_count')
			->select('GROUP_CONCAT(CASE WHEN tag_id IN (' . implode(',', array_keys(self::$mainArticleTags)) . ') THEN tag_id ELSE null END) AS matching_tags')
			->where('type_alias = \'com_content.article\'')
			->group('content_item_id');
		return '(' . trim((string) $tagQuery) . ')';
	}

	/**
	 * Function to get tag title from an array of tag ids
	 *
	 * @param  array  $tagArray  array of tag ids
	 *
	 * @return  array  associative array: tag id => tag title
	 */
	protected static function getTagTitles($tagIds)
	{
		JArrayHelper::toInteger($tagIds);
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('t.id, t.title')
			->from('#__tags AS t')
			->where('t.id IN (' . implode(',', $tagIds) . ')');
		$db->setQuery($query);
		$objectArray = $db->loadObjectList();
		$return = array();
		foreach ($objectArray as $object)
		{
			$return[$object->id] = $object->title;
		}
		return $return;
	}

	/**
	 * Function to test whether we are in an article view.
	 *
	 * @return boolean True if current view is an article
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
	 *
	 * @param string  $buffer  intro text to fix
	 * @return string          text with image tags fixed for SEF
	 */
	protected static function fixSefImages ($buffer) {
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

	/**
	 * Function for use in array_map to quote string values in an array
	 *
	 * @param string  $string  string to be quoted
	 * @return string          quoted string (using database quote method)
	 */
	protected static function dbQuote($string)
	{
		if ($string)
		{
			$string = JFactory::getDBO()->quote($string);
		}
		return $string;
	}

	/**
	 * Process article rows from query
	 *
	 * @param array  $rows  array of row objects from query
	 *
	 * @return array  processed array of row objects
	 */
	protected static function processArticleList($rows)
	{
		$related = array();

		if (count($rows) > 0)
		{
			foreach ($rows as $row)
			{
				$row->route = JRoute::_(ContentHelperRoute::getArticleRoute($row->slug, $row->catslug));
				$row->introtext = self::fixIntroText($row);
				$row->match_list = self::getMatchList($row);

				$related[] = $row;
			}
		}
		return $related;
	}

	/**
	 * Get the current article and its tags and set class values
	 *
	 * @param integer $id
	 */
	protected static function selectArticle($id)
	{
		$db	= JFactory::getDBO();
		// select the author info from the item
		$query = 'SELECT a.metakey, a.catid, a.created_by, a.created_by_alias,' .
			' cc.title as category_title, u.name as author ' .
			' FROM #__content AS a' .
			' LEFT JOIN #__categories AS cc ON cc.id = a.catid' .
			' LEFT JOIN #__users AS u ON u.id = a.created_by' .
			' WHERE a.id = '.(int) $id;
		$db->setQuery($query);
		self::$mainArticle = $db->loadObject();

		// If ignoring all tags, we don't need to get the tags for the article
		if (!self::$params->get('ignore_all_tags', 0))
		{
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
	}

	/**
	 * Sets the query order by and date selection
	 *
	 * @param   JRegistry       $params  module parameters
	 * @return  JDatabaseQuery  $query
	 */
	protected static function setDateOrderBy($params)
	{
		$query = JFactory::getDbo()->getQuery(true);

		// get the ordering for the query
		if ($params->get('showDate', 'none') == 'modify')
		{
			$query->select('a.modified as date');
			$dateOrderby = 'a.modified';
		}
		elseif ($params->get('showDate', 'none') == 'published')
		{
			$query->select('a.publish_up as date');
			$dateOrderby = 'a.publish_up';
		}
		else
		{
			$query->select('a.created as date');
			$dateOrderby = 'a.created';
		}

		switch ($params->get('ordering', 'alpha'))
		{
			case 'alpha':
				$query->order('a.title');
				break;

			case 'rdate':
				$query->order($dateOrderby . ' DESC, a.title ASC');
				break;

			case 'date':
				$query->order($dateOrderby . ' ASC, a.title ASC');
				break;

			case 'bestmatch':
				$query->order('match_count DESC');
				break;

			case 'article_order':
				$query->order('cc.lft ASC, a.ordering ASC, a.title ASC');
				break;

			case 'random':
				$query->select('rand() as random');
				$query->order('random ASC');
				break;

			default:
				$query->order('a.title ASC');
		}
		return $query;
	}

}
