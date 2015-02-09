<?php
/*
This file is part of CMS Made Simple module: Tourney.
Copyright (C) 2014-2015 Tom Phane <tpgww@onepost.net>
Refer to licence and other details at the top of file Tourney.module.php
More info at http://dev.cmsmadesimple.org/projects/tourney
This class is not suitable for static calling.
*/

class tmtEditSetup
{
	private $any;
	private $committed;
	private $spare;

	/**
	GetMatchExists:
	@bracket_id: bracket being processed
	Check for existence of any match for the bracket, and any 'locked in' match
	Sets self::any, self::committed
	See also: tmtSchedule::MatchCommitted() which replicates some of this function
	Returns: Nothing
	*/
	function MatchExists($bracket_id)
	{
		$this->any = FALSE;
		$this->committed = FALSE;
		$pref = cms_db_prefix();
		$sql = 'SELECT 1 AS yes FROM '.$pref.'module_tmt_matches WHERE bracket_id=?';
		$db = cmsms()->GetDb();
		$rs = $db->SelectLimit($sql,1,-1,array($bracket_id));
		if($rs && !$rs->EOF) //any match exists
		{
			$rs->Close();
			$this->any = TRUE;
			$sql = 'SELECT 1 AS yes FROM '.$pref.
			'module_tmt_matches WHERE bracket_id=? AND status>='.MRES.
			' AND teamA IS NOT NULL AND teamA!=-1 AND teamB IS NOT NULL AND teamB!=-1';
			$rs = $db->SelectLimit($sql,1,-1,array($bracket_id));
			if($rs && !$rs->EOF) //match(es) other than byes recorded
				$this->committed = TRUE;
			else
			{
				if($rs) $rs->Close();
				$sql = 'SELECT timezone FROM '.$pref.'module_tmt_brackets WHERE bracket_id=?';
				$zone = $db->GetOne($sql,array($bracket_id));
				$dt = new DateTime('+'.LEADHOURS.' hours',new DateTimeZone($zone));
				$sql = 'SELECT 1 AS yes FROM '.$pref.'module_tmt_matches WHERE bracket_id=? AND status = '.FIRM.
				' AND playwhen IS NOT NULL AND playwhen < '.$dt->format('Y-m-d G:i:s').
				' AND teamA IS NOT NULL AND teamA != -1 AND teamB IS NOT NULL AND teamB != -1';
				$rs = $db->SelectLimit($sql,1,-1,array($bracket_id));
				if($rs && !$rs->EOF) //FIRM match(es) scheduled before min. leadtime from now
					$this->committed = TRUE;
			}
		}
		if($rs)
			$rs->Close();
	}

	/**
	SpareSlot:
	@bracket_id:
	$elim: boolean TRUE for KO/DE matches, FALSE for RR, default TRUE
	Check whether a suitable place is available for a team to be added to the bracket
	*/
	function SpareSlot($bracket_id,$elim=TRUE)
	{
		$this->spare = FALSE;
		$pref = cms_db_prefix();
		$db = cmsms()->GetDb();
		if($elim)
		{
			//for KO/DE bracket - 1st-round matches with a bye and corresponding 2nd round match not committed
			$sql = 'SELECT COUNT(1) AS num FROM '.$pref.'module_tmt_matches WHERE bracket_id=? AND status >= '.FIRM.' AND NOT (teamA = -1 OR teamB = -1)';
			$fc = $db->GetOne($sql,array($bracket_id));
			if(!$fc)
			{
				$this->spare = TRUE;
				return;
			}
			$sql = 'SELECT match_id,nextm FROM '.$pref.'module_tmt_matches WHERE bracket_id=?
AND (teamA = -1 OR teamB = -1)
AND match_id NOT IN (SELECT DISTINCT nextm FROM '.$pref.'module_tmt_matches WHERE bracket_id=? AND nextm IS NOT NULL)';
			$maybe = $db->GetAssoc($sql,array($bracket_id,$bracket_id));
			if($maybe)
			{
				$sql = 'SELECT status FROM '.$pref.'module_tmt_matches WHERE match_id=?';
				foreach($maybe as $next)
				{
					$nstat = $db->GetOne($sql,array($next));
					if($nstat !== FALSE && $nstat < FIRM)
					{
						$this->spare = TRUE;
						return;
					}
				}
			}
		}
		else
		{
			//for RR bracket - relatively few matches played
			$sql = 'SELECT COUNT(1) AS num FROM '.$pref.'module_tmt_teams WHERE bracket_id=? AND flags!=2';
			$tc = $db->GetOne($sql,array($bracket_id));
			$sql = 'SELECT COUNT(1) AS num FROM '.$pref.'module_tmt_matches WHERE bracket_id=? AND status >= '.FIRM;
			$mc = $db->GetOne($sql,array($bracket_id));
			$this->spare = ($tc <= 5)?($tc >= $mc) :($tc >= $mc * 2);
		}
	}

	function Setup(&$mod,&$smarty,&$data,$id,$returnid,$activetab='',$message='')
	{
		$gCms = cmsms();
		$config = $gCms->GetConfig();

		if (isset($data->readonly))
		{
			unset($data->readonly);
			$pmod = FALSE;
			$pscore = FALSE;
		}
		else
		{
			$pmod = $mod->CheckAccess('admod');
			$pscore = $pmod || $mod->CheckAccess('score');
		}

		$smarty->assign('canmod',$pmod ? 1:0);
//		$smarty->assign('canscore',$pscore ? 1:0);

		if(!empty($message)) $smarty->assign('message',$message);

		$smarty->assign('form_start',$mod->CreateFormStart($id,'addedit_comp',$returnid));
		$smarty->assign('form_end',$mod->CreateFormEnd());

		if($activetab == FALSE)
			$activetab = 'maintab';
		$smarty->assign('tabs_start',$mod->StartTabHeaders().
			$mod->SetTabHeader('maintab',$mod->Lang('tab_main'),($activetab == 'maintab')).
			$mod->SetTabHeader('scheduletab',$mod->Lang('tab_schedule'),($activetab == 'scheduletab')).
			$mod->SetTabHeader('advancedtab',$mod->Lang('tab_advanced'),($activetab == 'advancedtab')).
			$mod->SetTabHeader('charttab',$mod->Lang('tab_chart'),($activetab == 'charttab')).
			$mod->SetTabHeader('playerstab',$mod->Lang('tab_players'),($activetab =='playerstab')).
			$mod->SetTabHeader('matchestab',$mod->Lang('tab_matches'),($activetab =='matchestab')).
			$mod->SetTabHeader('resultstab',$mod->Lang('tab_results'),($activetab =='resultstab')).
//			$mod->SetTabHeader('historytab',$mod->Lang('tab_history'),($activetab =='historytab')).
			$mod->EndTabHeaders() . $mod->StartTabContent());
		$smarty->assign('tabs_end',$mod->EndTabContent());

		$smarty->assign('maintab_start',$mod->StartTab('maintab'));
		$smarty->assign('scheduletab_start',$mod->StartTab('scheduletab'));
		$smarty->assign('advancedtab_start',$mod->StartTab('advancedtab'));
		$smarty->assign('charttab_start',$mod->StartTab('charttab'));
		$smarty->assign('playertab_start',$mod->StartTab('playerstab'));
		$smarty->assign('matchtab_start',$mod->StartTab('matchestab'));
		$smarty->assign('resultstab_start',$mod->StartTab('resultstab'));
//		$smarty->assign('historytab_start',$mod->StartTab('historytab'));

		$smarty->assign('tab_end',$mod->EndTab());

		//accumulator for hidden items,to be parked on page
		$hidden = $mod->CreateInputHidden($id,'bracket_id',$data->bracket_id);
		if(!empty($data->added))
			$hidden .= $mod->CreateInputHidden($id,'newbracket',$data->bracket_id);
		$hidden .= $mod->CreateInputHidden($id,'active_tab').
			$mod->CreateInputHidden($id,'real_action');
		//accumulators for script funcs,to be parked at end of the page
		$jsfuncs = array();
		$jsloads = array();

		$smarty->assign('incpath',$mod->GetModuleURLPath().'/include/');
		if($pmod)
		{
			//setup some ajax-parameters - partial data for tableDnD::onDrop
			$url = $mod->CreateLink($id,'move_team',NULL,NULL,array('bracket_id'=>$data->bracket_id,'neworders'=>''),NULL,TRUE);
			$offs = strpos($url,'?mact=');
			$ajfirst = str_replace('amp;','',substr($url,$offs+1));
			$jsfuncs[] = <<< EOS
function ajaxData(droprow,dropcount) {
 var orders = [];
 $(droprow.parentNode).find('tr td.ord').each(function(){
  orders[orders.length] = this.innerHTML;
 });
 var ajaxdata = '$ajfirst'+orders.join();
 return ajaxdata;
}
function dropresponse(data,status) {
 if(status == 'success' && data) {
  var i = 1;
  $('#tmt_players').find('.ord').each(function(){\$(this).html(i++);});
  var name;
  var oddclass = 'row1';
  var evenclass = 'row2';
  i = true;
  $('#tmt_players').trigger('update').find('tbody tr').each(function() {
	name = i ? oddclass : evenclass;
	\$(this).removeClass().addClass(name);
	i = !i;
  });
 } else {
  $('#page_tabs').prepend('<p style="font-weight:bold;color:red;">{$mod->Lang('err_ajax')}!</p><br />');
 }
}

EOS;
			$onsort = <<< EOS
function () {
 var orders = [];
 $(this).find('tbody tr td.ord').each(function(){
  orders[orders.length] = this.innerHTML;
 });
 var ajaxdata = '$ajfirst'+orders.join();
 $.ajax({
  url: 'moduleinterface.php',
  type: 'POST',
  data: ajaxdata,
  dataType: 'text',
  success: function (data,status) {
   if(status == 'success' && data) {
     var i = 1;
     $('#tmt_players').find('.ord').each(function(){
	 \$(this).html(i++);
	 });
   } else {
    $('#page_tabs').prepend('<p style="font-weight:bold;color:red;">{$mod->Lang('err_ajax')}!</p><br />');
   }
  }
 });
}
EOS;
		}
		else
			$onsort = 'null'; //no sort-processing if no mods allowed

		$jsloads[] = <<< EOS
 $.SSsort.addParser({
  id: 'numberinput',
  is: function(s,node) {
   var n = node.childNodes[0];
   if(n && n.nodeName.toLowerCase() == 'input' && n.type.toLowerCase() == 'text') {
     var v = n.value;
     return (!isNaN(parseFloat(v)) && isFinite(v));
   } else {
    return false;
   }
  },
  format: function(s,node) {
   var v = node.childNodes[0].value;
   if (v) {
    var n = Number(v);
    return (isNaN(n)) ? Number.NEGATIVE_INFINITY:n;
   } else if ((v+'').length > 0) {
    return 0;
   } else {
    return Number.POSITIVE_INFINITY;
   }
  },
  watch: true,
  type: 'numeric'
 });
 $.SSsort.addParser({
  id: 'isoinput',
  is: function(s,node) {
   var n = node.childNodes[0];
   if(n && n.nodeName.toLowerCase() == 'input' && n.type.toLowerCase() == 'text') {
    p = /^[12]\d{3}[\/-][01]\d[\/-]\[0-3]\d +([01]\d|2[0-3])( *: *[0-5]\d){1,2}/;
    return p.test($.trim(n.value));
   } else {
    return false;
   }
  },
  format: function(s,node) {
   return Date.parse(node.childNodes[0].value);
  },
  watch: true,
  type: 'numeric'
 });
 $.SSsort.addParser({
  id: 'textinput',
  is: function(s,node) {
   var n = node.childNodes[0];
   return (n && n.nodeName.toLowerCase() == 'input' && n.type.toLowerCase() == 'text');
  },
  format: function(s,node) {
   var t = node.childNodes[0].value;
   return $.trim(t);
  },
  watch: true,
  type: 'text'
 });

 var opts = {
  sortClass: 'SortAble',
  ascClass: 'SortUp',
  descClass: 'SortDown',
  oddClass: 'row1',
  evenClass: 'row2',
  oddsortClass: 'row1s',
  evensortClass: 'row2s',
  onSorted: {$onsort}
 };
 $('#tmt_players').addClass('table_drag').addClass('table_sort').SSsort(opts);
 delete opts.onSorted;
 $('#tmt_matches').addClass('table_sort').SSsort(opts);
 $('#tmt_results').addClass('table_sort').SSsort(opts);
 $('.tem_name,.tem_seed,.mat_playwhen,.mat_playwhere,.res_playwhen').blur(function (ev) {
  \$(this).closest('table').trigger('update');
 });

EOS;
	$jsfuncs[] = <<< EOS
function eventCancel(ev) {
 if(!ev) {
  if(window.event) ev = window.event;
  else return;
 }
 if(ev.cancelBubble !== null) ev.cancelBubble = true;
 if(ev.stopPropagation) ev.stopPropagation();
 if(ev.preventDefault) ev.preventDefault();
 if(window.event) ev.returnValue = false;
 if(ev.cancel !== null) ev.cancel = true;
}
function set_action(btn) {
 $('#{$id}real_action').val(btn.name);
}
function set_tab() {
 var active = $('#page_tabs > .active');
 $('#{$id}active_tab').val(active.attr('id'));
}
function set_params(btn) {
 set_action(btn);
 set_tab();
}

EOS;

		$this->MatchExists($data->bracket_id);
		$funcs = new tmtData();

//========= MAIN OPTIONS ==========
		$main = array();
		$main[] = array(
			$mod->Lang('title_title'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_name',$data->name,50) :
			(($data->name) ? $data->name : '&nbsp;')
		);
		$main[] = array(
			$mod->Lang('title_desc'),
			($pmod) ?
			$mod->CreateTextArea(TRUE,$id,$data->description,'tmt_description','','','','',65,10,'','','style="height:100px;"') :
			(($data->description) ? $data->description : '&nbsp;')
		);
		$main[] = array(
			$mod->Lang('title_alias'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_alias',$data->alias,30) :
			(($data->alias) ? $data->alias : '&nbsp;'),
			$mod->Lang('help_alias')
		);
		$options = $funcs->GetTypeNames($mod);
		$main[] = array(
			$mod->Lang('title_type'),
			($pmod && !$this->committed) ?
			$mod->CreateInputDropdown($id,'tmt_type',$options,'',$data->type) :
			array_search($data->type,$options,TRUE).(($pmod)?$mod->CreateInputHidden($id,'tmt_type',$data->type):'')
		);
		$main[] = array(
			$mod->Lang('title_zone'),
			($pmod && !$this->committed) ?
			$mod->CreateInputDropdown($id,'tmt_timezone',$mod->GetTimeZones(),'',$data->timezone) :
			$data->timezone.(($pmod)?$mod->CreateInputHidden($id,'tmt_timezone',$data->timezone):''),
			$mod->Lang('help_zone2')
		);
		$main[] = array(
			$mod->Lang('title_teamsize'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_teamsize',$data->teamsize,2,2) : $data->teamsize
		);
		$options = array(
			$mod->Lang('seed_none')=>0,
			$mod->Lang('seed_toponly')=>1,
			$mod->Lang('seed_balanced')=>2,
			$mod->Lang('seed_unbalanced')=>3
		);
		$main[] = array(
			$mod->Lang('title_seedtype'),
			($pmod && !$this->committed) ?
			$mod->CreateInputDropdown($id,'tmt_seedtype',$options,'',$data->seedtype):
			array_search($data->seedtype,$options).(($pmod)?$mod->CreateInputHidden($id,'tmt_seedtype',$data->seedtype):''),
			$mod->Lang('help_seedtype')
		);
		$main[] = array(
			$mod->Lang('title_owner'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_owner',$data->owner,50) : (($data->owner)?$data->owner:'&nbsp;'),
			$mod->Lang('help_owner')
		);
		$main[] = array(
			$mod->Lang('title_contact'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_contact',$data->contact,50) : (($data->contact)?$data->contact:'&nbsp;'),
			$mod->Lang('help_contact')
		);
		$help = $mod->Lang('help_twt1');
		if($pmod)
		{
			$twt = new tmtTweet();
			if($twt->GetTokens($data->bracket_id,TRUE,TRUE))
				$help .= $mod->Lang('help_twt2',$data->twtfrom);
			else
				$help .= $mod->Lang('help_twt3');
			$help .= ' '.$mod->Lang('help_twt4').'<br /><br />'.
				$mod->CreateInputSubmit($id,'connect',$mod->Lang('connect'),
					'title="'.$mod->Lang('title_auth').'" onclick="set_params(this);"');
		}
		$main[] = array(
			$mod->Lang('title_twtfrom'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_twtfrom',$data->twtfrom,16) : (($data->twtfrom)?$data->twtfrom:'&nbsp;'),
			$help
		);
/*		if($pmod)
		{
			include(cms_join_path($config['root_path'],'lib','classes','class.groupoperations.inc.php'));
			$ob = new GroupOperations();
			$grpdata = $ob->LoadGroups();
			unset($ob);
			$grpnames = array($mod->Lang('no_groups')=>'none');
			if($grpdata)
			{
				$grpnames[$mod->Lang('all_groups')]='any';
				foreach($grpdata as &$thisgrp)
				{
					if($thisgrp->active == '1')
						$grpnames[$thisgrp->name] = $thisgrp->id;
				}
				unset($thisgrp);
				unset($grpdata);
			}
		}
		$main[] = array(
			$mod->Lang('title_admin_eds'),
			($pmod) ?
			$mod->CreateInputDropdown($id,'tmt_admin_editgroup',$grpnames,'',$data->admin_editgroup) : (($data->admin_editgroup)?$data->:'&nbsp;'),
			$mod->Lang('help_login')
		);
*/
		$ob =& $mod->GetModuleInstance('FrontEndUsers');
		if($ob)
		{
			//TODO filter on permitted groups only c.f. MBVFaq
			if($pmod)
				$grpnames = $ob->GetGroupList();
			unset($ob);
			$main[] = array(
				$mod->Lang('title_feu_eds'),
				($pmod) ?
				$mod->CreateInputDropdown($id,'tmt_feu_editgroup',array(
					$mod->Lang('no_groups')=>'none',
					$mod->Lang('all_groups')=>'any')+$grpnames,'',$data->feu_editgroup) : $data->feu_editgroup,
				$mod->Lang('help_login')
			);
		}

		$smarty->assign('main',$main);

//========= ADVANCED OPTIONS ==========

		$adv = array();
		$mail = class_exists('CMSMailer',FALSE);
		$tplhelp = array();
		$tplhelp[] = $mod->Lang('help_template');
		foreach(array(
		'title',
		'description',
		'owner',
		'contact',
		'where',
		'when',
		'date',
		'time',
		'opponent',
		'teams',
		'recipient',
		'toall'
		) as $varname) $tplhelp[] = '&nbsp;$'.$varname.': '.$mod->Lang('desc_'.$varname);
		$tplhelp[] = $mod->Lang('help_mailout_template');
		$help = implode('<br />',$tplhelp);
		if($mail)
		{
			if($pmod)
				$hidden .= $mod->CreateInputHidden($id,'tmt_html',0);
			$adv[] = array(
				$mod->Lang('title_emailhtml'),
				($pmod) ?
				$mod->CreateInputCheckbox($id,'tmt_html','1',$data->html) :
				(($data->html) ?  $mod->Lang('yes') : $mod->Lang('no'))
			);

			$adv[] = array(
				$mod->Lang('title_mailouttemplate'),
				($pmod) ?
				$mod->CreateTextArea(FALSE,$id,$data->motemplate,'tmt_motemplate','','','','',65,10,'','','style="height:8em"') :
				(($data->motemplate) ? $data->motemplate : '&nbsp;'),
				 $help
			);
		}
		$adv[] = array(
			$mod->Lang('title_tweetouttemplate'),
			($pmod) ?
			$mod->CreateTextArea(FALSE,$id,$data->totemplate,'tmt_totemplate','','','','',65,3,'','','style="height:3em"') :
			(($data->totemplate) ? $data->totemplate : '&nbsp;'),
			(($mail)?$mod->Lang('seeabove'):$help)
		);  //TODO maybe specific $tplhelp[]
		$tplhelp = array();
		$tplhelp[] = $mod->Lang('help_template');
		foreach(array(
		'title',
		'description',
		'where',
		'when',
		'date',
		'time',
		'report'
		) as $varname) $tplhelp[] = '&nbsp;$'.$varname.': '.$mod->Lang('desc_'.$varname);
		$tplhelp[] = $mod->Lang('help_mailin_template');
		$help = implode('<br />',$tplhelp);

		if($mail)
		{
			$adv[] = array(
				$mod->Lang('title_mailintemplate'),
				($pmod) ?
				$mod->CreateTextArea(FALSE,$id,$data->mitemplate,'tmt_mitemplate','','','','',65,10,'','','style="height:8em"') :
				(($data->mitemplate) ? $data->mitemplate : '&nbsp;'),
				 $help
			);
		}
		$adv[] = array(
			$mod->Lang('title_tweetintemplate'),
			($pmod) ?
			$mod->CreateTextArea(FALSE,$id,$data->titemplate,'tmt_titemplate','','','','',65,3,'','','style="height:3em"') :
			(($data->titemplate) ? $data->titemplate : '&nbsp;'),
			(($mail)?$mod->Lang('seeabove'):$help)
		);  //TODO maybe specific $tplhelp[]

		$adv[] = array(
			$mod->Lang('title_logic'),
			($pmod) ?
			$mod->CreateTextArea(FALSE,$id,$data->logic,'tmt_logic','','','','',65,15,'','','style="height:8em"') :
			(($data->logic) ? $data->logic : '&nbsp;'),
			$mod->Lang('help_logic')
		);

		$smarty->assign('advanced',$adv);

//========= SCHEDULE ==========

		$sched = array();
		if($pmod)
		{
			if(!$this->committed)
				$i = $mod->CreateInputText($id,'tmt_startdate',$data->startdate,30);
			elseif ($data->startdate)
				$i = $data->startdate.$mod->CreateInputHidden($id,'tmt_startdate',$data->startdate);
			else
				$i = '&nbsp'.$mod->CreateInputHidden($id,'tmt_startdate','');
		}
		elseif($data->startdate)
			$i = $data->startdate;
		else
			$i = '&nbsp';
		$sched[] = array(
			$mod->Lang('title_start_date'),
			$i,
			$mod->Lang('help_date')
		);
		$sched[] = array(
			$mod->Lang('title_end_date'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_enddate',$data->enddate,30) :
			(($data->enddate) ? $data->enddate : '&nbsp'),
			$mod->Lang('help_date')
		);

		$sched[] = array(
			$mod->Lang('title_calendar').' (NOT YET WORKING)',
			($pmod) ?
			$mod->CreateInputText($id,'tmt_calendarid',$data->calendarid,15,20) : (($data->calendarid)?$data->calendarid:'&nbsp;'),
			$mod->Lang('help_calendar')
		);
		$sched[] = array(
			$mod->Lang('title_match_on').' (NOT YET WORKING)',
			($pmod) ?
			$mod->CreateInputText($id,'tmt_match_days',$data->match_days,50,128) : (($data->match_days)?$data->match_days:'&nbsp;'),
			$mod->Lang('help_match_days').'<br />'.$mod->Lang('help_daysend')
		);
		$sched[] = array(
			$mod->Lang('title_match_times').' (NOT YET WORKING)',
			($pmod) ?
			$mod->CreateInputText($id,'tmt_match_hours',$data->match_hours,50,128) : (($data->match_hours)?$data->match_hours:'&nbsp;'),
			$mod->Lang('help_match_times').'<br />'.$mod->Lang('help_timesend')
		);
		$sched[] = array(
			$mod->Lang('title_same_time'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_sametime',$data->sametime,3,3) :
			(($data->sametime) ? $data->sametime : '&nbsp;'),
			$mod->Lang('help_same_time'),
		);

		$btns = array(
			$mod->Lang('none')=>'none',
			$mod->Lang('hours')=>'hours',
			$mod->Lang('days')=>'days');

		$grp = $mod->CreateInputRadioGroup($id,'tmt_placegaptype',$btns,$data->placegaptype,'','|R|');
		$parts = explode('|R|',$grp);
		array_pop($parts);
		switch($data->placegaptype)
		{
		 case 'hours':
			$vd = '';
		 	$vh = (isset($data->placegaphours))?$data->placegaphours:$data->placegap;
			break;
		 case 'days':
		 	$vd = (isset($data->placegapdays))?$data->placegapdays:$data->placegap;
			$vh = '';
			break;
		 default:
			$vd = '';
			$vh = '';
			break;
		}
		$sched[] = array(
			$mod->Lang('title_place_gap'),
			($pmod) ?
			$parts[0].
			'&nbsp;&nbsp;&nbsp;'.$mod->CreateInputText($id,'tmt_placegaphours',$vh,2,4).$parts[1].
			'&nbsp;&nbsp;&nbsp;'.$mod->CreateInputText($id,'tmt_placegapdays',$vd,2,2).$parts[2] :
			$data->placegap.' '.$mod->Lang($data->placegaptype),
			$mod->Lang('help_place_gap')
		);

		$btns = array(
			$mod->Lang('none')=>'none',
			$mod->Lang('minutes')=>'minutes',
			$mod->Lang('hours')=>'hours',
			$mod->Lang('days')=>'days');

		$grp = $mod->CreateInputRadioGroup($id,'tmt_playgaptype',$btns,$data->playgaptype,'','|R|');
		$parts = explode('|R|',$grp);
		array_pop($parts);
		switch($data->playgaptype)
		{
		 case 'minutes':
			$vd = '';
		 	$vh = '';
			$vm = (isset($data->playgapmins))?$data->playgapmins:$data->playgap;
			break;
		 case 'hours':
			$vd = '';
		 	$vh = (isset($data->playgaphours))?$data->playgaphours:$data->playgap;
			$vm = '';
			break;
		 case 'days':
		 	$vd = (isset($data->playgapdays))?$data->playgapdays:$data->playgap;
			$vh = '';
			$vm = '';
			break;
		 default:
			$vd = '';
			$vh = '';
			$vm = '';
			break;
		}
		$sched[] = array(
			$mod->Lang('title_play_gap'),
			($pmod) ?
			$parts[0].
			'&nbsp;&nbsp;&nbsp;'.$mod->CreateInputText($id,'tmt_playgapmins',$vm,2,2).$parts[1].
			'&nbsp;&nbsp;&nbsp;'.$mod->CreateInputText($id,'tmt_playgaphours',$vh,2,4).$parts[2].
			'&nbsp;&nbsp;&nbsp;'.$mod->CreateInputText($id,'tmt_playgapdays',$vd,2,2).$parts[3] :
			$data->playgap.' '.$mod->Lang($data->playgaptype),
			$mod->Lang('help_play_gap')
		);

		$smarty->assign('schedulers',$sched);

//========= CHART ==========

		$names = array();

		$names[] = array(
			$mod->Lang('title_final'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_final',$data->final,30) : (($data->final)?$data->final:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_semi'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_semi',$data->semi,30) : (($data->semi)?$data->semi:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_quarter'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_quarter',$data->quarter,30) : (($data->quarter)?$data->quarter:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_eighth'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_eighth',$data->eighth,30) : (($data->eighth)?$data->eighth:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_roundname'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_roundname',$data->roundname,30) : (($data->roundname)?$data->roundname:'&nbsp;'),
			$mod->Lang('help_match_names')
		);
		$names[] = array(
			$mod->Lang('title_against'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_versus',$data->versus,30) : (($data->versus)?$data->versus:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_defeated'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_defeated',$data->defeated,30) : (($data->defeated)?$data->defeated:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_cantie'),
			($pmod) ?
			$mod->CreateInputCheckbox($id,'tmt_cantie',1,$data->cantie,'class="pagecheckbox"') :
				($data->cantie?$mod->Lang('yesties'):$mod->Lang('noties'))
		);
		$names[] = array(
			$mod->Lang('title_tied'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_tied',$data->tied,30) : (($data->tied)?$data->tied:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_noop'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_bye',$data->bye,30) : (($data->bye)?$data->bye:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_forfeit'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_forfeit',$data->forfeit,30) : (($data->forfeit)?$data->forfeit:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_abandoned'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_nomatch',$data->nomatch,30) : (($data->nomatch)?$data->nomatch:'&nbsp;')
		);
		$names[] = array(
			$mod->Lang('title_cssfile'),
			($pmod) ?
			$mod->CreateInputText($id,'tmt_chartcss',$data->chartcss,20,128).
			' '.$mod->CreateInputSubmit($id,'upload_css',$mod->Lang('upload'),
				'title="'.$mod->Lang('upload_tip').'" onclick="set_params(this);"') :
			(($data->chartcss) ? $data->chartcss : '&nbsp;'),
			$mod->Lang('help_cssfile')
		);
		$tplhelp = array();
		$tplhelp[] = $mod->Lang('help_template');
		foreach(array(
		'title',
		'description',
		'owner',
		'contact',
		'image',
		'imgdate',
		'imgheight',
		'imgwidth',
		) as $varname) $tplhelp[] = '&nbsp;$'.$varname.': '.$mod->Lang('desc_'.$varname);
		$tplhelp[] = $mod->Lang('help_chttemplate');
		$help = implode('<br />',$tplhelp);

		$names[] = array(
			$mod->Lang('title_chttemplate'),
			($pmod) ?
			$mod->CreateTextArea(FALSE,$id,$data->chttemplate,'tmt_chttemplate','','','','',65,20,'','','style="height:10em"') :
			(($data->chttemplate) ? $data->chttemplate : '&nbsp;'),
			$help
		);
		
		$smarty->assign('names',$names);

		$smarty->assign('print',$mod->CreateInputSubmit($id,'print',$mod->Lang('plain'),
			'title="'.$mod->Lang('plain_tip').'" onclick="set_params(this);"'));

//========= TEAMS ==========

		$smarty->assign('ordertitle',$mod->Lang('title_order'));
		$smarty->assign('seedtitle',$mod->Lang('title_seed'));
		$smarty->assign('contacttitle',$mod->Lang('title_contact'));
		$smarty->assign('movetitle',$mod->Lang('title_move'));
		$isteam = ((int)$data->teamsize > 1); 
		$teamtitle = ($isteam) ? $mod->Lang('title_team') : $mod->Lang('title_player');
		$smarty->assign('teamtitle',$teamtitle);
		$finds = array('/class="(.*)"/','/id=.*\[\]" /'); //for xhtml string cleanup

		if($data->teams)
		{
			$count = count($data->teams);
			$teams = array();
			$indx = 1;
			$rowclass = 'row1'; //used to alternate row colors
			$theme = $gCms->variables['admintheme'];
			if($pmod)
			{
				$downtext = $mod->Lang('down');
				$uptext = $mod->Lang('up');
				$tmp = ($isteam) ? $mod->Lang('team') : $mod->Lang('player');
			}

			foreach($data->teams as $tid=>$tdata)
			{
				$one = new stdClass();
				$one->rowclass = $rowclass;
				if($pmod)
				{
					$one->hidden = $mod->CreateInputHidden($id,'tem_teamid[]',$tid).
						$mod->CreateInputHidden($id,'tem_contactall[]',$tdata['contactall']);
					$one->order = $tdata['displayorder'];
					$tmp = $mod->CreateInputText($id,'tem_name[]',$tdata['name'],20,64);
					$one->name = preg_replace($finds,array('class="tem_name $1"',''),$tmp);//fails if backref first!
					$tmp = $mod->CreateInputText($id,'tem_seed[]',$tdata['seeding'],3,3);
					$one->seed = preg_replace($finds,array('class="tem_seed $1"',''),$tmp);
					$tmp = $mod->CreateInputText($id,'tem_contact[]',$tdata['contact'],30,64);
					$one->contact = preg_replace($finds,array('class="tem_contact $1"',''),$tmp);
					//need input-objects that look like page-link, to get all form parameters upon activation
					if($indx > 1)
						$one->uplink = $mod->CreateInputLinks($id,'moveup['.$tid.']','arrow-u.gif',FALSE,
							$uptext,'onclick="set_params(this);"');
					else
						$one->uplink = '';
					if($indx < $count)
						$one->downlink = $mod->CreateInputLinks($id,'movedown['.$tid.']','arrow-d.gif',FALSE,
							$downtext,'onclick="set_params(this);"');
					else
						$one->downlink = '';
					$indx++;
					$one->editlink = $mod->CreateInputLinks($id,'edit['.$tid.']','edit.gif',FALSE,
						$mod->Lang('edit'),'onclick="set_params(this);"');
					$one->deletelink = $mod->CreateInputLinks($id,'delete_team['.$tid.']','delete.gif',FALSE,
						$mod->Lang('delete')); //confirmation via modal dialog
					$one->selected = $mod->CreateInputCheckbox($id,'tsel[]',$tid,-1,'class="pagecheckbox"');
				}
				else
				{
					$one->order = $tdata['displayorder'];
					$one->name = $tdata['name'];
					$one->seed = $tdata['seeding'];
					$one->contact = $tdata['contact'];
				}

				$teams[] = $one;
				($rowclass=='row1'?$rowclass='row2':$rowclass='row1');
			}
			$smarty->assign('teams',$teams);

			if($pmod)
			{
				$jsloads[] = <<< EOS
 $('#tmt_players').find('.tem_delete').children().modalconfirm({
  overlayID: 'confirm',
  preShow: function(d){
	 var teamname = \$(this).closest('tr').find('.tem_name').attr('value');
	 var para = d.children('p:first')[0];
	 para.innerHTML = '{$mod->Lang('confirm_delete','%s')}'.replace('%s',teamname);
  },
  onConfirm: function(){
	 set_tab();
	 $('#{$id}real_action').val(this.name);
	 return true;
  }
 });

EOS;
			}
		}
		else //no team-data
		{
			$count = 0;
			$smarty->assign('noteams',$mod->Lang('info_noteam'));
		}
		$smarty->assign('teamcount',$count);

		if($pmod)
		{
			$this->SpareSlot($data->bracket_id,($data->type != RRTYPE));
			if($this->spare)
			{
				$linktext = $mod->Lang('title_add',strtolower($teamtitle));
				//need input-object that looks like page-link, to get all form parameters upon activation
				$smarty->assign('addteam',$mod->CreateInputLinks($id,'addteam','newobject.gif',TRUE,
					$linktext,'onclick="set_params(this);"'));
			}
		}

		if($count)
		{
			if($count > 1)
			{
				$jsfuncs[] = <<< EOS
function select_all_teams() {
 var st = $('#teamsel').attr('checked');
 if(!st) st = false;
 $('#tmt_players > tbody').find('input[type="checkbox"]').attr('checked',st);
}

EOS;
				$smarty->assign('selteams',$mod->CreateInputCheckbox($id,'t',FALSE,-1,
					'id="teamsel" onclick="select_all_teams();"'));
			}
			$jsfuncs[] = <<< EOS
function team_count() {
 var cb = $('#tmt_players > tbody').find('input:checked');
 return cb.length;
}
function teams_selected(ev,btn) {
 if(team_count() > 0) {
  set_params(btn);
  return true;
 } else {
  eventCancel(ev);
  return false;
 }
}

EOS;

			if($pmod)
			{
				$smarty->assign('dndhelp',$mod->Lang('help_dnd'));
				$smarty->assign('update1',$mod->CreateInputSubmit($id,'update['.$id.'teams]',$mod->Lang('update'),
					'title="'.$mod->Lang('update_tip').'" onclick="return teams_selected(event,this);"'));
				$smarty->assign('delete',$mod->CreateInputSubmit($id,'delteams',$mod->Lang('delete'),
					'title="'.$mod->Lang('delete_tip').'"'));
				$t = ($isteam) ? $mod->Lang('sel_teams') : $mod->Lang('sel_players');
				$t = $mod->Lang('confirm_delete',$t);
				$jsloads[] = <<< EOS
 $('#{$id}delteams').modalconfirm({
  overlayID: 'confirm',
  doCheck: function(){
	 return (team_count() > 0);
  },
  preShow: function(d){
	 var para = d.children('p:first')[0];
	 para.innerHTML = '{$t}';
  },
  onConfirm: function(){
	 set_tab();
	 $('#{$id}real_action').val(this.name);
	 return true;
  }
 });

EOS;
			}
			$smarty->assign('export',$mod->CreateInputSubmit($id,'export',$mod->Lang('export'),
				'title="'.$mod->Lang('export_tip').'" onclick="return teams_selected(event,this);"'));
		}
		$smarty->assign('import',$mod->CreateInputSubmit($id,'import_team',$mod->Lang('import'),
			'title="'.$mod->Lang('import_tip').'" onclick="set_params(this);"'));

//========== MATCHES ===========

		if(empty($data->matchview))
			$data->matchview = 'actual';
		$plan = ($data->matchview != 'actual');

		if($data->matches)
		{
			if($plan)
			{
				switch ($data->type)
				{
				 case RRTYPE:
					$anon = $mod->Lang('anonother');
					break;
				 case DETYPE:
				 	$rnd = new tmtRoundsDE();
					//partial bracket data for downstream to use when naming
					$bdata = array(
					 'final'=>$data->final,
					 'semi'=>$data->semi,
					 'quarter'=>$data->quarter,
					 'eighth'=>$data->eighth,
					 'roundname'=>$data->roundname,
					 'bye'=>$data->bye
					);
				 	$tc = count($data->teams);
				 	break;
				 default:
				 	$rnd = new tmtRoundsKO();
				 	break;
				}
			}
			if($pmod)
			{
				$items = array(
					$mod->Lang('notyet')=>NOTYET,
					$mod->Lang('possible')=>SOFT,
					$mod->Lang('confirmed')=>FIRM);
				$group = $mod->CreateInputRadioGroup($id,'mat_status',$items,'','','|');
				$choices = explode('|',$group);
			}

			$rowclass = 'row1';
			$matches = array();
			foreach($data->matches as $mid=>$mdata)
			{
				$one = new stdClass();
				$one->rowclass = $rowclass;
				if($plan)
				{
					$one->mid = $mid;
					$prev = FALSE;
					if($mdata['teamA'] != NULL)
					{
						$one->teamA = $mod->TeamName($mdata['teamA'],FALSE);
						if(!$one->teamA)
							$one->teamA = $data->bye;
					}
					else
					{
						switch ($data->type)
						{
						 case RRTYPE:
							$one->teamA = $anon;
							break;
						 case DETYPE:
							$level = $rnd->MatchLevel($tc,$data->matches,$mid);
							$one->teamA = $rnd->MatchTeamID_Team($mod,$bdata,$tc,$data->matches,$mid,$level,$mdata['teamB']); //team id may be NULL or -1
						 	break;
						 default:
							$one->teamA = $rnd->MatchTeamID_Mid($mod,$data->matches,$mid);
						 	break;
						}
					}

					if($mdata['teamB'] != NULL)
					{
						$one->teamB = $mod->TeamName($mdata['teamB'],FALSE);
						if(!$one->teamB)
						{
							if($one->teamA != $data->bye)
								$one->teamB = $data->bye;
							else
							{
								$one->teamA = $mod->Lang('nomatch');
								$one->teamB = '';
							}
						}
					}
					else
					{
						switch ($data->type)
						{
						 case RRTYPE:
							$one->teamB = $anon;
							break;
						 case DETYPE:
							$level = $rnd->MatchLevel($tc,$data->matches,$mid);
							if($mdata['teamA'])
								$name = $rnd->MatchTeamID_Team($mod,$bdata,$tc,$data->matches,$mid,$level,$mdata['teamA']);
							else
							{
								$excl = key($data->matches);
								if($excl)
									$excl--;
								$name = $rnd->MatchTeamID_Mid($mod,$bdata,$tc,$data->matches,$mid,$level,$excl);
							}
							if($name != $data->bye || $one->teamA != $data->bye)
								$one->teamB = $name;
							else //don't display 2 byes
							{
								$one->teamA = $mod->Lang('nomatch');
								$one->teamB = '';
							}
						 	break;
						 default:
						 	$excl = ($mdata['teamA']) ? (int)($mdata['teamA']) : key($data->matches)-1;
							$one->teamB = $rnd->MatchTeamID_Mid($mod,$data->matches,$mid,$excl);
						 	break;
						}
					}
				}
				else
				{
					$one->teamA = $mod->TeamName($mdata['teamA']);
					$one->teamB = $mod->TeamName($mdata['teamB']);
				}

				if($pmod)
				{
					$tmp = $mod->CreateInputText($id,'mat_playwhen[]',$mdata['playwhen'],20,48);
					$repls = array('class="mat_playwhen $1"','');
					$one->schedule = preg_replace($finds,$repls,$tmp);
					$tmp = $mod->CreateInputText($id,'mat_playwhere[]',$mdata['place'],20,64);
					$repls = array('class="mat_playwhere $1"','');
					$one->place = preg_replace($finds,$repls,$tmp);
					$one->hidden = $mod->CreateInputHidden($id,'mat_teamA[]',$mdata['teamA']).
						$mod->CreateInputHidden($id,'mat_teamB[]',$mdata['teamB']);
					if($mdata['status'] >= MRES)
					{
						//completed, no going back
						$one->hidden .= $mod->CreateInputHidden($id,'mat_status['.$mid.']',$mdata['status']);
						if($mdata['teamA'] == -1 || $mdata['teamB'] == -1)
							$one->btn1 = '';
						else
							$one->btn1 = $mod->Lang('status_complete');
						$one->btn2 = '';
						$one->btn3 = '';
					}
					else
					{
						$r = 'mat_status['.$mid.']"'; //unique name for each radio-group,also returns match-id
						$one->btn1 = str_replace('mat_status"',$r,$choices[0]);
						$one->btn2 = str_replace('mat_status"',$r,$choices[1]);
						$one->btn3 = str_replace('mat_status"',$r,$choices[2]);
						if ($mdata['status'] == ASOFT || $mdata['status'] == AFIRM)
						{
							$one->btn2 = str_replace('value="'.SOFT,'value="'.ASOFT,$one->btn2);
							$one->btn3 = str_replace('value="'.FIRM,'value="'.AFIRM,$one->btn3);
						}
						//select relevant radio item
						switch(intval($mdata['status']))
						{
						 case SOFT:
						 case ASOFT:
							$one->btn2 = str_replace(' />',' checked="checked" />',$one->btn2);
							break;
						 case FIRM:
						 case AFIRM:
							$one->btn3 = str_replace(' />',' checked="checked" />',$one->btn3);
							break;
						 default:
							$one->btn1 = str_replace(' />',' checked="checked" />',$one->btn1);
							break;
						}
					}
					$one->selected = $mod->CreateInputCheckbox($id,'msel[]',$mid,-1,'class="pagecheckbox"');
				}
				else //no change allowed
				{
					$one->schedule = $mdata['playwhen'];
					$one->place = $mdata['place'];
					$one->btn1 = '';
					$one->btn2 = '';
					switch(intval($mdata['status']))
					{
					 case SOFT:
					 case ASOFT:
						$one->btn3 = $mod->Lang('possible');
						break;
					 case FIRM:
					 case AFIRM:
						$one->btn3 = $mod->Lang('confirmed');
						break;
					 default:
						$one->btn3 = $mod->Lang('notyet');
						break;
					}
					$one->selected = '';
				}

				$matches[] = $one;
				($rowclass=='row1'?$rowclass='row2':$rowclass='row1');
			}
			$smarty->assign('matches',$matches);

			if($pmod && count($matches) > 1)
			{
				$jsfuncs[] = <<< EOS
function select_all_matches() {
 var st = $('#matchsel').attr('checked');
 if(!st) st = false;
 $('#tmt_matches > tbody').find('input[type="checkbox"]').attr('checked',st);
}

EOS;

				$smarty->assign('selmatches',$mod->CreateInputCheckbox($id,'m',FALSE,-1,
					'id="matchsel" onclick="select_all_matches();"'));
			}

			$jsfuncs[] = <<< EOS
function match_count() {
 var cb = $('#tmt_matches > tbody').find('input:checked');
 return cb.length;
}
function matches_selected(ev,btn) {
 if(match_count() > 0) {
  set_params(btn);
  return true;
 } else {
  eventCancel(ev);
  return false;
 }
}

EOS;
			$smarty->assign('scheduledtitle',$mod->Lang('scheduled'));
			$smarty->assign('placetitle',$mod->Lang('title_venue'));
			$smarty->assign('statustitle',$mod->Lang('title_status'));
			if($pmod)
			{
				$smarty->assign('update2',$mod->CreateInputSubmit($id,'update['.$id.'matches]',$mod->Lang('update'),
					'title="'.$mod->Lang('update_tip').'" onclick="return matches_selected(event,this);"'));
				if(!$this->committed)	//no match actually 'locked in'
				{
					$smarty->assign('reset',$mod->CreateInputSubmit($id,'reset',$mod->Lang('reset'),
						'title="'.$mod->Lang('reset_tip').'"'));
					$jsloads[] = <<< EOS
 $('#{$id}reset').modalconfirm({
  overlayID: 'confirm',
  preShow: function(d){
	 var para = d.children('p:first')[0];
	 para.innerHTML = '{$mod->Lang('confirm_delete',$mod->Lang('match_data'))}';
  },
  onConfirm: function(){
	 set_tab();
	 $('#{$id}real_action').val(this.name);
	 return true;
  }
 });

EOS;
				}
			}

			$jsloads[] = <<< EOS
 $('#{$id}notify').modalconfirm({
  overlayID: 'confirm',
  doCheck: function(){
	 return (match_count() > 0);
  },
  preShow: function(d){
	 var para = d.children('p:first')[0];
	 para.innerHTML = '{$mod->Lang('allsaved')}';
  },
  onConfirm: function(){
	 set_tab();
	 $('#{$id}real_action').val(this.name);
	 return true;
  }
 });

EOS;
			$smarty->assign('notify',$mod->CreateInputSubmit($id,'notify',$mod->Lang('notify'),
				'title="'.$mod->Lang('notify_tip').'"')); // onclick="matches_notify(event,this);"'));
			if($plan)
			{
				$bdata = array(
				 'bracket_id'=>$data->bracket_id,
				 'name'=>$data->name,
				 'description'=>'',
				 'type'=>$data->type,
				 'chartcss'=>$data->chartcss,
				 'timezone'=>$data->timezone,
				 'final'=>$data->final,
				 'semi'=>$data->semi,
				 'quarter'=>$data->quarter,
				 'eighth'=>$data->eighth,
				 'roundname'=>$data->roundname,
				 'versus'=>$data->versus,
				 'defeated'=>$data->defeated,
				 'tied'=>$data->tied,
				 'bye'=>$data->bye,
				 'forfeit'=>$data->forfeit,
				 'nomatch'=>$data->nomatch,
				 'chartbuild'=>TRUE
				);
				$lyt = new tmtLayout();
				list($chartfile,$errkey) = $lyt->GetChart($mod,$bdata,$data->chartcss,2);
				if ($chartfile)
				{
					//nobody else should see this chart
					$db = cmsms()->GetDb();
		 			$sql = 'UPDATE '.cms_db_prefix().'module_tmt_brackets SET chartbuild=1 WHERE bracket_id=?';
					$db->Execute($sql,array($data->bracket_id));
					$basename = basename($chartfile);
					list($height,$width) = $lyt->GetChartSize();
					$smarty->assign('image',$mod->CreateImageObject($config['root_url'].'/tmp/'.$basename,(int)$height+30));
				}
				else
				{
					$message = $mod->Lang('err_chart');
						if($errkey)
							$message .= '<br /><br />'.$mod->Lang($errkey);
					$smarty->assign('image',$message);
				}
			}
			$smarty->assign('malldone',0);
		}
		elseif($this->any) //match(es) exist
		{
			$smarty->assign('malldone',1);
			$smarty->assign('nomatches',$mod->Lang('info_nomatch2'));
		}
		else //no matches at all
		{
			$smarty->assign('malldone',0);
			$smarty->assign('nomatches',$mod->Lang('info_nomatch'));
			if($pmod)
				$smarty->assign('schedule',$mod->CreateInputSubmit($id,'schedule',$mod->Lang('schedule'),
					'onclick="set_params(this);"'));
		}

		$hidden .= $mod->CreateInputHidden($id,'matchview',$data->matchview);

		$jsfuncs[] = <<< EOS
function matches_view(btn) {
 set_tab();
 $('#{$id}real_action').val('match_view');
 var newmode = (btn.name=='{$id}actual')?'actual':'plan';
 $('#{$id}matchview').val(newmode);
}

EOS;
		if($plan)
		{
			$smarty->assign('plan',1);
			$smarty->assign('idtitle',$mod->Lang('title_mid'));
			$smarty->assign('altmview',$mod->CreateInputSubmit($id,'actual',$mod->Lang('actual'),
				'title="'.$mod->Lang('actual_tip').'" onclick="matches_view(this);"'));
		}
		else
		{
			$smarty->assign('plan',0);
			$smarty->assign('altmview',$mod->CreateInputSubmit($id,'plan',$mod->Lang('plan'),
				'title="'.$mod->Lang('plan_tip').'" onclick="matches_view(this);"'));
		}
		//these may be used on results tab as well or instead
		$smarty->assign('chart',$mod->CreateInputSubmit($id,'chart',$mod->Lang('chart'),
			'onclick="set_params(this);"'));
		$smarty->assign('list',$mod->CreateInputSubmit($id,'list',$mod->Lang('list'),
			'onclick="set_params(this);"'));

//========= RESULTS ==========

		if(empty($data->resultview))
			$data->resultview = 'future';
		$future = ($data->resultview == 'future');

		if($data->results) //matching results in the database
		{
			if($future)
				$selone = $mod->Lang('chooseone');
			$relations = $mod->ResultTemplates($data->bracket_id,FALSE);
			$results = array();
			$rowclass = 'row1';
			//populate array excluding byes
			foreach($data->results as $mid=>$mdata)
			{
				if(!($mdata['teamA']=='-1'|| $mdata['teamB']=='-1'))
				{
					$one = new stdClass();
					$one->rowclass = $rowclass;
					$one->schedule = $mdata['playwhen'];
					if($pmod)
					{
						$one->hidden = $mod->CreateInputHidden($id,'res_matchid[]',$mid).
							$mod->CreateInputHidden($id,'res_teamA[]',$mdata['teamA']).
							$mod->CreateInputHidden($id,'res_teamB[]',$mdata['teamB']);
						$tmp = $mod->CreateInputText($id,'res_playwhen[]',$one->schedule,15,30);
						$repls = array('class="res_playwhen $1"','');
						$one->actual = preg_replace($finds,$repls,$tmp);
						$one->teamA = $mod->TeamName($mdata['teamA']);
						$one->teamB = $mod->TeamName($mdata['teamB']);
						$choices = array(
							str_replace('%s',$one->teamA,$relations['won'])=>WONA,
							str_replace('%s',$one->teamB,$relations['won'])=>WONB,
							str_replace('%s',$one->teamA,$relations['forf'])=>FORFA,
							str_replace('%s',$one->teamB,$relations['forf'])=>FORFB
						);
						if($data->cantie)
							$choices[$data->tied] = MTIED;
						$choices[$data->nomatch] = NOWIN;
						if($future)
						{
							$choices = array($selone=>-1) + $choices;
							$sel = -1;
						}
						else
							$sel = intval($mdata['status']);
						$one->result = $mod->CreateInputDropdown($id,'res_status['.$mid.']',$choices,'',$sel);
						$tmp = $mod->CreateInputText($id,'res_score[]',$mdata['score'],15,30);
						$repls = array('class="res_score $1"','');
						$one->score = preg_replace($finds,$repls,$tmp);
						$one->selected = $mod->CreateInputCheckbox($id,'rsel[]',$mid,-1,'class="pagecheckbox"');
					}
					else //no changes
					{
						$one->actual = substr($mdata['playwhen'],0,-3); //without any seconds display
						$one->teamA = '';
						$one->teamB = '';
						$tA = $mod->TeamName($mdata['teamA']);
						$tB = $mod->TeamName($mdata['teamB']);
						switch(intval($mdata['status']))
						{
						 case WONA:
							$one->result = str_replace('%s',$tA,$relations['won']);
							break;
						 case WONB:
							$one->result = str_replace('%s',$tB,$relations['won']);
							break;
						 case FORFA:
							$one->result = str_replace('%s',$tA,$relations['forf']);
							break;
						 case FORFB:
							$one->result = str_replace('%s',$tB,$relations['forf']);
							break;
						 case MTIED:
							$one->result = sprintf($relations['tied'],$tA,$tB);
							break;
						 case NOWIN:
							$one->result = $mod->Lang('name_abandoned');
							break;
						 default:
							$one->result = $mod->Lang('notyet');
							break;
						}
						$one->score = $mdata['score'];
					}
					$results[] = $one;
					($rowclass=='row1'?$rowclass='row2':$rowclass='row1');
				}
			}

			if($results) //there's something other than a bye
			{
				$smarty->assign('results',$results);
				if(count($results) > 1)
				{
					$jsfuncs[] = <<< EOS
function select_all_results() {
 var st = $('#resultsel').attr('checked');
 if(!st) st = false;
 $('#tmt_results > tbody').find('input[type="checkbox"]).attr('checked',st);
}

EOS;
					$smarty->assign('selresults',$mod->CreateInputCheckbox($id,'r',FALSE,-1,
						'id="resultsel" onclick="select_all_results();"'));
				}

				$smarty->assign('playedtitle',$mod->Lang('played'));
				$smarty->assign('resulttitle',$mod->Lang('title_result'));
				$smarty->assign('scoretitle',$mod->Lang('score'));

				$jsfuncs[] = <<< EOS
function result_count() {
 var cb = $('#tmt_results > tbody').find('input:checked');
 return cb.length;
}
function results_selected(ev,btn) {
 if(result_count() > 0) {
  set_params(btn);
  return true;
 } else {
  eventCancel(ev);
  return false;
 }
}

EOS;
				$smarty->assign('update3',$mod->CreateInputSubmit($id,'update['.$id.'results]',$mod->Lang('update'),
					'title="'.$mod->Lang('update_tip').'" onclick="return results_selected(event,this);"'));
			}
			else //no relevant result
			{
				$smarty->assign('ralldone',0);
				$smarty->assign('noresults',$mod->Lang('info_noresult'));
			}
		}
		elseif($this->any) //any match in the database
		{
			$smarty->assign('ralldone',1);
			$key = ($this->committed) ? 'info_noresult2':'info_noresult';
			$smarty->assign('noresults',$mod->Lang($key));
		}
		else //nothing at all
		{
			$smarty->assign('ralldone',0);
			$smarty->assign('noresults',$mod->Lang('info_noresult'));
		}

		$hidden .= $mod->CreateInputHidden($id,'resultview',$data->resultview);

		$jsfuncs[] = <<< EOS
function results_view(btn) {
 set_tab();
 $('#{$id}real_action').val('result_view');
 var newmode = (btn.name=='{$id}future')?'future':'past';
 $('#{$id}resultview').val(newmode);
}

EOS;
		if($future)
			$smarty->assign('altrview',$mod->CreateInputSubmit($id,'past',$mod->Lang('history'),
				'title="'.$mod->Lang('history_tip').'" onclick="results_view(this);"'));
		else
			$smarty->assign('altrview',$mod->CreateInputSubmit($id,'future',$mod->Lang('future'),
				'title="'.$mod->Lang('future_tip').'" onclick="results_view(this);"'));
		$smarty->assign('changes',$mod->CreateInputSubmit($id,'changelog',$mod->Lang('changes'),
			'title="'.$mod->Lang('changes_tip').'" onclick="set_action(this);"'));

//===============================

		$smarty->assign('save',$mod->CreateInputSubmit($id,'submit',$mod->Lang('save'),
			'onclick="set_action(this);"'));
		$smarty->assign('apply',$mod->CreateInputSubmit($id,'apply',$mod->Lang('apply'),
			'title = "'.$mod->Lang('apply_tip').'" onclick="set_params(this);"'));
		//setup cancel-confirmation popup
		if(!empty($data->added)) //allways check cancellation for new bracket
			$test = 'null';
		else //check if 'dirty' reported via ajax
		{
			$url = $mod->CreateLink($id,'check_data',NULL,NULL,array('bracket_id'=>$data->bracket_id),NULL,TRUE);
			$offs = strpos($url,'?mact=');
			$ajaxdata = str_replace('amp;','',substr($url,$offs+1));
			$test = <<< EOS
function(){
	 var check = false;
	 $.ajax({
		url: 'moduleinterface.php',
		async: false,
		type: 'POST',
		data: '$ajaxdata',
		dataType: 'text',
		success: function(data,status) {
			if(status=='success') check = (data=='1');
		}
	 }).fail(function() {
		alert('{$mod->Lang('err_ajax')}');
	 });
	 return check;
	}
EOS;
		}

		$smarty->assign('cancel',$mod->CreateInputSubmit($id,'cancel',$mod->Lang('cancel')));

		//for popup confirmation
		$smarty->assign('no',$mod->Lang('no'));
		$smarty->assign('yes',$mod->Lang('yes'));
		//onCheckFail: true means onConfirm() if no check needed
		$jsloads[] = <<< EOS
 $('#{$id}cancel').modalconfirm({
  overlayID: 'confirm',
  doCheck: {$test},
  preShow: function(d){
	 var para = d.children('p:first')[0];
	 para.innerHTML = '{$mod->Lang('abandon')}';
  },
  onCheckFail: true,
  onConfirm: function(){
	 $('#{$id}real_action').val(this.name);
	 return true;
  }
 });

EOS;
		$smarty->assign('hidden',$hidden);

		if($jsloads)
		{
			$jsfuncs[] = '
$(document).ready(function() {
';
			$jsfuncs = array_merge($jsfuncs,$jsloads);
			$jsfuncs[] = '});
';
		}
		$smarty->assign('jsfuncs',$jsfuncs);
	}
}

?>
