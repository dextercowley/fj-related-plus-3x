<?php
/**
 * @version		$Id: key_word.php 53 2010-12-05 16:13:50Z dextercowley $
 * @package		mod_fj_related_plus
 * @copyright	Copyright (C) 2008 Mark Dexter. All rights reserved.
 * @license		http://www.gnu.org/licenses/gpl.html
 */
// no direct access
defined('_JEXEC') or die('Restricted access');
JHTML::_('behavior.tooltip');
$showDate 			= $params->def('showDate', 'none') != 'none';
$showCount 			= $params->def('showMatchCount', 0);
$matchAuthor 		= $params->def('matchAuthor', 0);
$matchAuthorAlias 	= $params->def('matchAuthorAlias', 0);
$matchCategory 		= $params->def('fjmatchCategory');
$mainArticleTags 	= modFJRelatedPlusHelper::$mainArticleTags; // get tag array for main article
$mainArticleAlias 	= modFJRelatedPlusHelper::$mainArticleAlias; // alias value for main article
$mainArticleAuthor 	= modFJRelatedPlusHelper::$mainArticleAuthor; // author id of main article
$mainArticleCategory = modFJRelatedPlusHelper::$mainArticleCategory; // category id of main article
$tagLabel 			= $params->def('tagLabel', '');
$dateFormat 		= $params->def('date_format', JText::_('DATE_FORMAT_LC4'));
$showTooltip 		= $params->get('show_tooltip', '1');
$titleLinkable 		= $params->get('fj_title_linkable');

$outputArray = array();

foreach ($list as $item) // loop through articles
{
	foreach ($item->match_list as $matchTag) // loop through match list for the article
	{
		foreach ($mainArticleTags as $nextId => $nextTag) // loop through the key words for the main aritcle
		{
			// find main article match. this eliminates duplcates based on upper and lower case
			if (trim(JString::strtoupper($nextTag)) == JString::strtoupper($matchTag))
			{
				$thisTag = $nextTag;
			}
		}

		if (($matchAuthorAlias) && ($mainArticleAlias)
				&& (JString::strtoupper($mainArticleAlias) == JString::strtoupper($matchWord))) {
			$thisTag = $mainArticleAlias;
		}
		else if (($matchAuthor) && ($mainArticleAuthor == $matchWord)) {
			$thisTag = $item->author;
		}
		if (($matchCategory) && ($mainArticleCategory == $matchWord)) {
			$thisTag = $item->category_title;
		}

		$outputArray[$thisTag][] = $item;
		$thisTag = '';
	}
}

ksort($outputArray, SORT_STRING | SORT_FLAG_CASE);  // sort tags alphabetically ?>

<ul class="relateditems<?php echo $params->get('moduleclass_sfx'); ?>">
<?php foreach ($outputArray as $thisTag => $articleList) : ?>
	<?php if ($thisTag)  : ?>
		<li><strong>
		<?php echo (($tagLabel) ? $tagLabel . ' ' : '') . $thisTag; ?>
		</strong>
		<ul>
		<?php foreach ($articleList as $thisArticle) : ?>
			<li>
			<?php if (($showTooltip) && ($titleLinkable)) : ?>
				<a href="<?php echo $thisArticle->route;?>" class="fj_relatedplus<?php echo $params->get('moduleclass_sfx');?>">
				<span class="hasTip" title="<?php echo htmlspecialchars($thisArticle->title);?>::<?php echo $thisArticle->introtext;?>">
				<?php echo $thisArticle->title;?>
				<?php if ($showDate) echo ' - ' . JHTML::_('date', $thisArticle->date, $dateFormat); ?>
				<?php if ($showCount)
				{
					if ($thisArticle->match_count == 1)
					{
						echo ' (1 ' . JText::_('match') . ')';
					}
					else
					{
						echo ' (' . $thisArticle->match_count . ' '. JText::_('matches') . ')';
					}
				} ?>
				</span></a>
			<?php endif; ?>

			<?php if (!($showTooltip) && ($titleLinkable)) : ?>
				<a href="<?php echo $thisArticle->route;?>" class="fj_relatedplus<?php echo $params->get('moduleclass_sfx');?>">
				<?php echo $thisArticle->title;?>
				<?php if ($showDate) echo ' - ' . JHTML::_('date', $thisArticle->date, $dateFormat); ?>
				<?php if ($showCount)
				{
					if ($thisArticle->match_count == 1)
					{
						echo ' (1 ' . JText::_('match') . ')';
					}
					else
					{
						echo ' (' . $thisArticle->match_count . ' '. JText::_('matches') . ')';
					}
				} ?>
				</a>
			<?php endif;?>

			<?php if (($showTooltip) && !($titleLinkable)) : ?>
				<span class="fj_relatedplus<?php echo $params->get('moduleclass_sfx');?>">
				<span class="hasTip" title="<?php echo htmlspecialchars($thisArticle->title);?>::<?php echo $thisArticle->introtext;?>">
				<?php echo $thisArticle->title;?>
				<?php if ($showDate) echo ' - ' . JHTML::_('date', $thisArticle->date, $dateFormat); ?>
				<?php if ($showCount)
				{
					if ($thisArticle->match_count == 1)
					{
						echo ' (1 ' . JText::_('match') . ')';
					}
					else
					{
						echo ' (' . $thisArticle->match_count . ' '. JText::_('matches') . ')';
					}
				} ?>
				</span></span>
			<?php endif; ?>
			<?php if (!($showTooltip) && !($titleLinkable)) : ?>
				<span class="fj_relatedplus<?php echo $params->get('moduleclass_sfx');?>">
				<?php echo $thisArticle->title;?>
				<?php if ($showDate) echo ' - ' . JHTML::_('date', $thisArticle->date, $dateFormat); ?>
				<?php if ($showCount)
				{
					if ($thisArticle->match_count == 1)
					{
						echo ' (1 ' . JText::_('match') . ')';
					}
					else
					{
						echo ' (' . $thisArticle->match_count . ' '. JText::_('matches') . ')';
					}
				} ?>
				</span>
			<?php endif;?>

			</li>
		<?php endforeach;?>
		</ul><br/></li>
	<?php endif; ?>
<?php endforeach;?>
</ul>