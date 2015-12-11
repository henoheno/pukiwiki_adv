<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// $Id: backup.inc.php,v 1.29.26 2015/12/06 00:20:00 Logue Exp $
// Copyright (C)
//   2010-2015 PukiWiki Advance Developers Team
//   2008 PukioWikio Developers Team
//   2005-2008 PukiWiki Plus! Team
//   2002-2005,2007 PukiWiki Developers Team
//   2001-2002 Originally written by yu-ji
// License: GPL v2 or (at your option) any later version
//
// Backup plugin
use PukiWiki\Auth\Auth;
use PukiWiki\Router;
use PukiWiki\Factory;
use PukiWiki\File\WikiFile;
use PukiWiki\Listing;
use PukiWiki\Renderer\RendererFactory;
use PukiWiki\Diff\Diff;
use PukiWiki\Utility;
use PukiWiki\Time;

// Prohibit rendering old wiki texts (suppresses load, transfer rate, and security risk)
// define('PLUGIN_BACKUP_DISABLE_BACKUP_RENDERING', PKWK_SAFE_MODE || PKWK_OPTIMISE);
define('PLUGIN_BACKUP_DISABLE_BACKUP_RENDERING', Auth::check_role('safemode') || PKWK_OPTIMISE);

// ロールバック機能を有効にする
defined('PLUGIN_BACKUP_USE_ROLLBACK') or define('PLUGIN_BACKUP_USE_ROLLBACK', TRUE);

// 管理人のみロールバック機能を使える
defined('PLUGIN_BACKUP_ROLLBACK_ADMINONLY') or define('PLUGIN_BACKUP_ROLLBACK_ADMINONLY', TRUE);

/**
 * plugin_backup_init()
 * backup plugin initialization.
 * load necessary libraries.
 */
function plugin_backup_init()
{
	global $_string;
	$messages = array(
		'_backup_messages' => array(
			'btn_delete'			=> T_('Delete'),
			'btn_jump'				=> T_('Jump'),
			'msg_backup'			=> T_('backup'),
			'msg_backup_adminpass'	=> T_('Please input the password for deleting.'),
			'msg_backup_deleted'	=> T_('Backup of $1 has been deleted.'),
			'msg_backuplist'		=> T_('Backup list'),
			'msg_deleted'			=> T_(' $1 has been deleted.'),
			'msg_diff'				=> T_('diff'),
			'msg_diff_add'			=> T_('The added line is <ins class="diff_added">THIS COLOR</ins>.'),
			'msg_diff_del'			=> T_('The deleted line is <del class="diff_removed">THIS COLOR</del>.'),
			'msg_goto'				=> T_('Go to $1.'),
			'msg_invalidpass'		=> $_string['invalidpass'],
			'msg_nobackup'			=> T_('There are no backup(s) of $1.'),
			'msg_nowdiff'			=> T_('diff current'),
			'msg_source'			=> T_('source'),
			'msg_rollback'			=> T_('Roll back'),
			'msg_version'			=> T_('Versions:'),
			'msg_view'				=> T_('View the $1.'),
			'msg_visualdiff'		=> T_('diff for visual'),
			'msg_arrow'				=> T_('-&gt;'),
			'msg_delete'			=> T_('Delete'),

			'title_backup'			=> T_('Backup of $1(No. $2)'),
			'title_backup_delete'	=> T_('Deleting backup of $1'),
			'title_backupdiff'		=> T_('Backup diff of $1(No. $2)'),
			'title_backuplist'		=> T_('Backup list'),
			'title_backupnowdiff'	=> T_('Backup diff of $1 vs current(No. $2)'),
			'title_backupsource'	=> T_('Backup source of $1(No. $2)'),
			'title_pagebackuplist'	=> T_('Backup list of $1'),

			'btn_rollback'				=> T_('Roll back'),
			'btn_selectdelete'			=> T_('Delete selected backup(s).'),
			'msg_backup_rollbacked'		=> T_('Rolled back to $1.'),
			'title_backup_rollback'		=> T_('Roll back from a backup(No. %s), this page.'),
			'title_backup_rollbacked'	=> T_('This page has been rolled back from a backup(No. %s).')
		)
	);
	set_plugin_messages($messages);
}

function plugin_backup_action()
{
	global $vars, $do_backup, $_string, $_button;
	global $_backup_messages;
	if (! $do_backup) return;

	$page = isset($vars['page']) ? $vars['page']  : null;
	$action = isset($vars['action']) ? $vars['action'] : null;
	$s_age  = ( isset($vars['age']) && is_numeric($vars['age']) ) ? $vars['age'] : 0;

	/**
	 * if page is not set, show list of backup files
	 */
	if (!$page) {
		return array('msg'=>$_backup_messages['title_backuplist'], 'body'=>plugin_backup_get_list_all());
	}

	$wiki = Factory::Wiki($page);
	$is_page = $wiki->has();
	$s_page = Utility::htmlsc($page);
	$r_page = rawurlencode($page);

	$backups = Factory::Backup($page)->get();

	$msg = $_backup_messages['msg_backup'];
	if ($s_age > count($backups)) $s_age = count($backups);
	$body = '';

	$wiki->checkReadable();

	if ($s_age <= 0) {
		return array(
			'msg'=>$_backup_messages['title_pagebackuplist'],
			'body'=>plugin_backup_get_list($page)
		);
	}
	$body .= '<div class="panel panel-default">';
	$body .= plugin_backup_get_list($page);
	$body .= '</div>'."\n";

	if ($action){
		$data = join("\n", $backups[$s_age]['data']);
		Auth::is_role_page($data);
		switch ($action){
			case 'delete' :
				/**
				 * 指定された世代を確認。指定されていなければ、一覧のみ表示
				 */
				// checkboxが選択されずにselectdeleteを実行された場合は、削除処理をしない
				if(! isset($vars['selectages']) &&		// checkboxが選択されていない
					isset($vars['selectdelete'])) {		// 選択削除ボタンが押された
														// 何もしない
				} else {
					if(! isset($vars['selectages'])) {	// 世代引数がない場合は全削除
						return plugin_backup_delete($page);
					}
					return plugin_backup_delete($page, $vars['selectages']);
				}
			case 'rollback' :
				return plugin_backup_rollback($page, $s_age);
			break;
			case 'diff':
				if (Auth::check_role('safemode')) Utility::dieMessage( $_string['prohibit'] );
				$title = & $_backup_messages['title_backupdiff'];
				$past_data = ($s_age > 1) ? join("\n", $backups[$s_age - 1]['data']) : '';

				Auth::is_role_page($past_data);
				$body .= plugin_backup_diff($past_data, $data);
			break;
			case 'nowdiff':
				if (Auth::check_role('safemode')) die_message( $_string['prohibit'] );
				$title = & $_backup_messages['title_backupnowdiff'];
				$now_data = Factory::Wiki($page)->get(true);
				Auth::is_role_page($now_data);
				$body .= plugin_backup_diff($data, $now_data);
			break;
			case 'visualdiff':
				$old = join('', $backups[$s_age]['data']);
				$now_data = get_source($page, TRUE, TRUE);
				Auth::is_role_page($now_data);
				// <ins> <del>タグを使う形式に変更。
				$diff = new Diff($data, $now_data);

				$source = plugin_backup_visualdiff($diff->getDiff());
				$body .= drop_submit(RendererFactory::factory($source));
				$body = preg_replace('#<p>\#del(.*?)(</p>)#si', '<del class="remove_block">$1', $body);
				$body = preg_replace('#<p>\#ins(.*?)(</p>)#si', '<ins class="add_block">$1', $body);
				$body = preg_replace('#<p>\#delend(.*?)(</p>)#si', '$1</del>', $body);
				$body = preg_replace('#<p>\#insend(.*?)(</p>)#si', '$1</ins>', $body);
				// ブロック型プラグインの処理が無いよ～！
				$body = preg_replace('#&amp;del;#i', '<del class="remove_word">', $body);
				$body = preg_replace('#&amp;ins;#i', '<ins class="add_word">', $body);
				$body = preg_replace('#&amp;delend;#i', '</del>', $body);
				$body = preg_replace('#&amp;insend;#i', '</ins>', $body);
				$title = & $_backup_messages['title_backupnowdiff'];
			break;
			case 'source':
				if (Auth::check_role('safemode')) die_message( $_string['prohibit'] );
				$title = & $_backup_messages['title_backupsource'];
				$body .= '<pre class="sh" data-blush="plain">' . htmlsc($data) . '</pre>' . "\n";
			break;
			default:
				if (PLUGIN_BACKUP_DISABLE_BACKUP_RENDERING) {
					die_message( T_('This feature is prohibited') );
				} else {
					$title = & $_backup_messages['title_backup'];
					$body .= drop_submit(RendererFactory::factory($data));
				}
			break;
		}
		$msg = str_replace('$2', $s_age, $title);
	}

	if (! Auth::check_role('readonly')) {
		$body .= '<a class="button" href="' . $wiki->uri('backup', $page, null, array('action'=>'delete')) . '">' .
			str_replace('$1', $s_page, $_backup_messages['title_backup_delete']) . '</a>';
	}

	return array('msg'=>$msg, 'body'=>$body);
}

/**
 * function plugin_backup_delete
 * Delete backup
 * @param string $page Page name.
 * @param array $ages Ages to delete.
 */
function plugin_backup_delete($page, $ages = array())
{
	global $vars;
	global $_backup_messages;

	$backup = Factory::Backup($page);
	if (! $backup->has())
		return array('msg'=>$_backup_messages['title_pagebackuplist'], 'body'=>plugin_backup_get_list($page)); // Say "is not found"

	if (! Auth::check_role('role_contents_admin')) {
		$backup->remove();
		return array(
			'msg'  => $_backup_messages['title_backup_delete'],
			'body' => str_replace('$1', make_pagelink($page), $_backup_messages['msg_backup_deleted'])
		);
	}

	$body = array();
	if (isset($vars['pass'])) {
		if (Auth::login($vars['pass'])) {
			//_backup_delete($page, $ages);
			return array(
				'msg'  => $_backup_messages['title_backup_delete'],
				'body' => str_replace('$1', make_pagelink($page), $_backup_messages['msg_backup_deleted'])
			);
		} else {
			$body[] = '<p style="alert alert-danger">' . $_backup_messages['msg_invalidpass'] . '</p>';
		}
	}

	$body[] = '<fieldset>';
	$body[] = '<legend>' . $_backup_messages['msg_backup_adminpass'] . '</legend>';
	$body[] = '<form action="' . Router::get_script_uri() . '" method="post" class="form-inline plugin-backup-delete-form">';
	$body[] = '<input type="hidden" name="cmd" value="backup" />';
	$body[] = '<input type="hidden" name="page" value="' . Utility::htmlsc($page) . '" />';
	$body[] = '<input type="hidden" name="action" value="delete" />';
	foreach ($ages as $age) {
		$body[] = '<input type="hidden" name="selectages[]" value="' . $age . '" />';
	}
	$body[] = '<div class="form-group">';
	$body[] = '<input type="password" name="pass" size="12" required="true" />';
	$body[] = '</div>';
	$body[] = '<input class="btn btn-danger" type="submit" name="ok" value="' . $_backup_messages['btn_delete'] . '" />';
	$body[] = '</form>';
	$body[] = '</fieldset>';
	return	array('msg'=>$_backup_messages['title_backup_delete'], 'body'=>join("\n",$body));
}

function plugin_backup_diff($a, $b)
{
	global $_backup_messages;
	$ul = <<<EOD
<ul class="no-js">
	<li>{$_backup_messages['msg_diff_add']}</li>
	<li>{$_backup_messages['msg_diff_del']}</li>
</ul>
EOD;
	$diff = new Diff($a, $b);
	return $ul . $diff->getHtml() . "\n";
}

function plugin_backup_get_list($page)
{
	global $_backup_messages, $vars, $_button;
	$retval = array();
	$retval[] = '<p><a class="btn btn-secondary" href="'.Router::get_page_uri($page).'">'.$_button['back'].'</a></p>';

	$backup = Factory::Backup($page);
	$backups = $backup->get();
	if (empty($backups)) {
		$retval[] = '<p class="alert alert-info">' . str_replace('$1', make_pagelink($page), $_backup_messages['msg_nobackup']) . '</p>';
		return join('', $retval);
	}else{
		$retval[] = '<form action="'.Router::get_script_uri().'" method="get" class="backup_select_form">';
		$retval[] = '<input type="hidden" name="cmd" value="backup" />';
		$retval[] = '<input type="hidden" name="page" value="'.Utility::htmlsc($page).'" />';
		$age = isset($vars['age']) ? (int)$vars['age'] : null;
		$action = isset($vars['action']) && empty($vars['action']) ? $vars['action'] : 'diff';

		$actions = array(
			'nowdiff'	=> $_backup_messages['msg_nowdiff'],
			'diff'		=> $_backup_messages['msg_diff'],
			'visaldiff'	=> $_backup_messages['msg_visualdiff'],
			'source'	=> $_backup_messages['msg_source'],
			'delete'	=> $_backup_messages['msg_delete'],
			'rollback'	=> $_backup_messages['msg_rollback']
		);

		if (IS_MOBILE) {
			$retval[] = '<select name="age">';
			foreach ($backups as $backup_age=>$data) {
				$time = isset($data['real']) ? $data['real'] :
					isset($data['time']) ? $data['time'] : '';
				$retval[] = '<option value="' . $backup_age . '"' .
					( $backup_age === $age ? ' selected="selected"' : '' ).'>' . Time::format($time, false) . '</option>';
			}
			$retval[] = '</select>';
		}else{
			$retval[] = '<div class="panel panel-default">';
			$retval[] = '<div class="panel-heading">';
		}
		foreach ($actions as $val=>$act_name){
			$retval[] = '<label class="radio-inline">';
			$retval[] = '<input type="radio" name="action" value="'.$val.'"'.( ($val === $action) ? ' checked="checked"' : '' ).' />'.$act_name;
			$retval[] = '</label>';
		}
		if (IS_MOBILE) {
			$retval[] = '</fieldset>';
			$retval[] = '<input type="submit" value="' . $_backup_messages['btn_jump'] . '" />';
		}else{
			$retval[] = '<input type="submit"  class="btn btn-info" value="' . $_backup_messages['btn_jump'] . '" />';
			$retval[] = '</div>';
			$retval[] = '<div class="panel-body list_pages">';
			$retval[] = '<ol>';
			foreach ($backups as $backup_age=>$data) {
				$time = isset($data['real']) ? $data['real'] :
					isset($data['time']) ? $data['time'] : '';

				$retval[] = '<li><input type="radio" name="age" value="' . $backup_age . '" id="r_' . $backup_age  . '"' .
					( $backup_age === $age ? ' checked="checked"' : '' ).' /><label for="r_' . $backup_age . '">' . Time::format($time, false) . '</label>' .
					( (! Auth::check_role('safemode')) ? '<input type="checkbox" name="selectages[]" value="'.$backup_age.'" />' : '')
					 . '</li>';
			}
			$retval[] = '</ol>';
			$retval[] = '</div>';
		}
	}
	$retval[] = '</form>';
/*
	$backups = _backup_file_exists($page) ? get_backup($page) : array();
	if (empty($backups)) {
		$retval[1] .= '   <li>' . str_replace('$1', make_pagelink($page), $_backup_messages['msg_nobackup']) . '</li>';
		return join('', $retval);
	}
	$_anchor_from = $_anchor_to   = '';
	$safemode = Auth::check_role('safemode');
	foreach ($backups as $age=>$data) {
		if (! PLUGIN_BACKUP_DISABLE_BACKUP_RENDERING) {
			$_anchor_from = '<a href="' . get_cmd_uri('backup', $page, null, array('age'=>$age)) . '">';
			$_anchor_to   = '</a>';
		}
		if (isset($data['real'])) {
			$time = $data['real'];
		}else if(isset($data['time'])){
			$time = $data['time'];
		}else{
			$time = '';
		}
		$retval[1] .= '<li>';
		if (! $safemode) {
			$retval[1] .= '<input type="checkbox" name="selectages[]" value="'.$age.'" />';
		}
		$retval[1] .= $_anchor_from . format_date($time, TRUE) . $_anchor_to;

		if (! $safemode) {
			$retval[1] .= ' <nav class="navibar" style="display:inline;"><ul>';
			$retval[1] .= '<li><a href="'. get_cmd_uri('backup', $page, null, array('action'=>'diff', 'age'=>$age)). '">' . $_backup_messages['msg_diff'] . '</a></li>';
			$retval[1] .= '<li><a href="'. get_cmd_uri('backup', $page, null, array('action'=>'nowdiff', 'age'=>$age)). '">' . $_backup_messages['msg_nowdiff'] . '</a></li>';
			$retval[1] .= '<li><a href="'. get_cmd_uri('backup', $page, null, array('action'=>'visualdiff', 'age'=>$age)). '">' . $_backup_messages['msg_visualdiff'] . '</a></li>';
			$retval[1] .= '<li><a href="'. get_cmd_uri('backup', $page, null, array('action'=>'source', 'age'=>$age)). '">' . $_backup_messages['msg_source'] . '</a></li>';
			if (PLUGIN_BACKUP_USE_ROLLBACK) {
				$retval[1] .= '<li><a href="'. get_cmd_uri('backup', $page, null, array('action'=>'rollback', 'age'=>$age)). '">' . $_backup_messages['msg_rollback'] . '</a></li>';
			}
			$retval[1] .= '</ul></nav>';
		}

		$retval[1] .= '</li>'."\n";
	}
*/

	return join("\n", $retval);
}

// List for all pages
function plugin_backup_get_list_all($withfilename = FALSE)
{
	global $_string;

	if (Auth::check_role('safemode')) die_message( $_string['prohibit'] );

	return Listing::get('backup', 'backup', $withfilename);
}

// Plus! Extend - Diff
function plugin_backup_visualdiff($str)
{
	$str = preg_replace('/^(\x20)(.*)$/m', "\x08$2", $str);
	$str = preg_replace('/^(\-)(\x20|#\x20|\-\-\-|\-\-|\-|\+\+\+|\+\+|\+|>|>>|>>>)(.*)$/m', "\x08$2&del;$3&delend;", $str);
	$str = preg_replace('/^(\+)(\x20|#\x20|\-\-\-|\-\-|\-|\+\+\+|\+\+|\+|>|>>|>>>)(.*)$/m', "\x08$2&ins;$3&insend;", $str);
	$str = preg_replace('/^(\-)(.*)$/m', "#del\n$2\n#delend", $str);
	$str = preg_replace('/^(\+)(.*)$/m', "#ins\n$2\n#insend", $str);
	$str = preg_replace('/^(\x08)(.*)$/m', '$2', $str);
	return $str;
}

// Plus! Extend - Create Combobox for Backup
function plugin_backup_convert()
{
	global $vars;
	global $_backup_messages;
	global $js_blocks, $plugin_backup_count;

	$page   = isset($vars['page']) ? $vars['page']   : null;
	if (empty($page)) return;
	
	check_readable($page, false);

	// Get arguments
	$with_label = TRUE;
	$diff_mode = 0;
	$args = func_get_args();
	while (isset($args[0])) {
		switch(array_shift($args)) {
			case 'default'    : $diff_mode = 0; break;
			case 'nowdiff'    : $diff_mode = 1; break;
			case 'visualdiff' : $diff_mode = 2; break;
			case 'label'      : $with_label = TRUE;  break;
			case 'nolabel'    : $with_label = FALSE; break;
		}
	}

	$mode = 'nowdiff';
	switch($diff_mode) {
		case 2:
			$mode = 'visualdiff';
			break;
		case 1:
			$mode = 'nowdiff';
			break;
	}

	$r_page = rawurlencode($page);
	$s_page = Utility::htmlsc($page);
	$retval = array();
	$date = get_date("m/d", get_filetime($page));
	$backups =  Factory::Backup($page)->has() ?  Factory::Backup($page)->get() : array();

	$retval[] = '<form action="' . Router::get_script_uri() . '" method="get" class="autosubmit form-inline plugin-backup-form">';
	$retval[] = '<input type="hidden" name="cmd" value="backup" />';
	$retval[] = '<input type="hidden" name="action" value="' . $mode . '" />';
	$retval[] = '<input type="hidden" name="page" value="' . $s_page . '" />';
	$retval[] = '<div class="input-group">';
	$retval[] = $with_label ? '<label for="age">'.$_backup_messages['msg_version'].'</label>' : '';
	$retval[] = '<select id="age" name="age" class="form-control">';

	//$retval[] = '<option value="" selected="selected" data-placeholder="true" disabled="disabled">'.$_backup_messages['msg_backup'].'</option>';
	if (count($backups) == 0) {
		$retval[] = '<option value="" selected="selected" disabled="disabled">' .$_backup_messages['msg_arrow'] . ' ' . $date . '(No.1)</option>';
	}else{
		$maxcnt = count($backups) + 1;
		$retval[] = '<option value="" selected="selected" disabled="disabled">' . $_backup_messages['msg_arrow'] . ' ' . $date . '(No.' . $maxcnt . ')</option>';
		$backups = array_reverse($backups, True);
		foreach ($backups as $age=>$data) {
			if (isset($data['real'])) {
				$time = $data['real'];
			}else if(isset($data['time'])){
				$time = $data['time'];
			}else{
				break;
			}
			$date = get_date('m/d', $time);
			$retval[] = '<option value="' . $age . '">' . $date . ' (No.' . $age . ')</option>';
		}
	}
	$retval[] = '</select>';
	$retval[] = '<span class="input-group-btn"><button type="submit" class="btn btn-success"><span class="fa fa-chevron-right"></span></button></span>';
	$retval[] = '</div>';
	$retval[] = '</form>';
	return join("\n",$retval);
}

/**
 * function plugin_backup_rollback($page, $age)
 */
function plugin_backup_rollback($page, $age)
{
	global $vars;
	global $_backup_messages;

	$passvalid = isset($vars['pass']) ? Auth::login($vars['pass']) : FALSE;

	if ($passvalid) {
		$backup = Factory::Backup($page);
		$backups = $backup->get($age);
		if( empty($backups) )
		{
			return array(sprintf($_backup_messages['title_backup_rollback'], $age), 'body'=>$_backup_messages['msg_nobackup']);	// Do nothing
		}

		$wiki = Factory::Wiki($page);
		// バックアップからロールバック（タイムスタンプを更新しない状態で）
		$wiki->set($backups['data']);
		// ファイルの更新日時をバックアップの時点にする
		$wiki->touch($backups['time']);

		//put_lastmodified();

		return array(
			'msg'  => $_backup_messages['title_backup_rollbacked'],
			'body' => str_replace('$1', make_pagelink($page) . '(No. ' . $age . ')', $_backup_messages['msg_backup_rollbacked'])
		);
	}else{
		$script = Router::get_script_uri();
		$s_page = htmlsc($page);
		$body = <<<EOD
<fieldset>
	<legend>{$_backup_messages['msg_backup_adminpass']}</legend>
	<form action="$script" method="post" class="plugin-backup-rollback-form form-inline">
		<input type="hidden" name="cmd" value="backup" />
		<input type="hidden" name="action" value="rollback" />
		<input type="hidden" name="age" value="$age" />
		<input type="hidden" name="page" value="$s_page" />
		<div class="form-group">
			<input type="password" name="pass" size="12" class="form-control" />
		</div>
		<input type="submit" name="ok" value="{$_backup_messages['btn_rollback']}" class="btn btn-warning" />
	</form>
</legend>
EOD;
		return	array('msg'=>sprintf($_backup_messages['title_backup_rollback'], $age), 'body'=>$body);
	}
}
/* End of file backup.inc.php */
/* Location: ./wiki-common/plugin/backup.inc.php */
