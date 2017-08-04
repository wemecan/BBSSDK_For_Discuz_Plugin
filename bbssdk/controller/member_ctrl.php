<?php
if(!defined('DISABLEDEFENSE'))  exit('Access Denied!');
require_once libfile('function/misc');
require_once libfile('function/mail');
require_once libfile('function/member');
require_once libfile('class/member');
require_once libfile('function/discuzcode');
require_once libfile('function/profile');

class Member extends BaseCore
{
	function __construct()
	{
		parent::__construct();
	}
	public function get_item()
	{
		global $_G;
		$uid = intval($this->uid);

		if(!$uid) return_status(403);

		$final = array();
		$member = getuserbyuid($uid);
		if($uid == $member['uid']){
			$userinfo =  C::t('common_member')->fetch_all_stat_memberlist($member['username']);
			$userinfo = array_merge($userinfo[$uid],$member);
			$final = $this->relation_item($userinfo);
		}
		$this->success_result($final);
	}
	public function get_login()
	{
		global $_G;
		$username = urldecode($this->username);
		$email = urldecode($this->email);
		$password = urldecode($this->password);
		$answer = urldecode($this->answer);
		$clientip = urldecode($_REQUEST['clientip']);
		$questionid = empty($_REQUEST['questionid']) ? 0 : intval($_REQUEST['questionid']);

		if( ( empty($username) && empty($email) ) || empty($password) || empty($clientip)){
			return_status(403);
		}

		if(!preg_match('%utf%is', $this->charset)){
			if(function_exists('iconv')){
				$username = iconv('UTF-8', $this->charset . '//ignore', $username);
				$answer = iconv('UTF-8', $this->charset . '//ignore', $answer);
			}else{
				$username = mb_convert_encoding($username, $this->charset, 'UTF-8');
				$answer = mb_convert_encoding($answer, $this->charset, 'UTF-8');
			}
		}

		$clientip = '';

		if(empty($email)){
			$result = userlogin($username, $password, $questionid, $answer, 'username', $clientip);
		}else{
			$result = userlogin($email,$password,$questionid,$answer,'email',$clientip);
		}

		if((int)$result['ucresult']['uid'] < 0)
		{
			switch ($result['ucresult']['uid']) {
				case -1:
					return_status(30103);
					break;
				case -2:
					return_status(30105);
					break;
				case -3:
					$lang = lang('template');
					$question = array();
					for($i=1;$i<=7;$i++)
					{
						if(isset($lang['security_question_'.$i])){
							$question[$i-1]['questionid'] = $i;
							$question[$i-1]['question'] = $lang['security_question_'.$i];
						}
					}
					return_status(30101,array('data'=>$question));
					break;
			}
		}

		$uid = $_G['uid'] = $result['ucresult']['uid'];

		$ctlObj = new logging_ctl();
		$ctlObj->setting = $_G['setting'];
		if($result['status'] == -1) {
			if(!$ctlObj->setting['fastactivation']) {
				return_status(30102);
			}
			$init_arr = explode(',', $ctlObj->setting['initcredits']);
			$groupid = $ctlObj->setting['regverify'] ? 8 : $ctlObj->setting['newusergroupid'];
			C::t('common_member')->insert($uid, $result['ucresult']['username'], md5(random(10)), $result['ucresult']['email'], $clientip, $groupid, $init_arr);
			$result['member'] = getuserbyuid($uid);
			$result['status'] = 1;
		}
		if($result['status'] > 0) {
			if($ctlObj->extrafile && file_exists($ctlObj->extrafile)) {
				require_once $ctlObj->extrafile;
			}
			C::t('common_member_status')->update($uid, array('lastip' => $clientip, 'lastvisit' =>TIMESTAMP, 'lastactivity' => TIMESTAMP));
			if(isset($result['member']['password'])){
				unset($result['member']['password']);
			}
			if(isset($result['member']['credits'])){
				unset($result['member']['credits']);
			}
			//登录成功				
			$userinfo =  C::t('common_member')->fetch_all_stat_memberlist($result['member']['username']);
			$userinfo = array_merge($userinfo[$uid],$result['member']);
			$final = $this->relation_item($userinfo);
			$this->success_result($final);
		}
		if($_G['member_loginperm'] > 1) {
			//登录失败
			return_status(30104);
		}elseif($_G['member_loginperm'] == -1) {
			//密码错误
			return_status(30105);
		}else{
			return_status(30106);
		}
	}

	public function post_register()
	{
		global $_G;
		$username = urldecode($this->username);
		$email = urldecode($this->email);
		$clientip = urldecode($this->clientip);
		$password = urldecode($this->password);

		if(function_exists('iconv')){
			$username = iconv('UTF-8', $this->charset . '//ignore', $username);
		}else{
			$username = mb_convert_encoding($username, $this->charset, 'UTF-8');
		}

		if(isset($email) && !empty($email)){
			$email = strtolower($email);
		}

		if(empty($username) || empty($email) || empty($clientip) || empty($password)){
			return_status(403);
		}

		$usernameLen = dstrlen($username);
		if($usernameLen < 3){
			return_status(302011,'用户名过短');
		}
		if($usernameLen > 15){
			return_status(302012,'用户名过长');
		}

		$ctlObj = new register_ctl();
		$ctlObj->setting = $_G['setting'];
		if(isset($ctlObj->setting['pwlength']) && $ctlObj->setting['pwlength']) {
			if(strlen($password) < $ctlObj->setting['pwlength']) {
				return_status(302013,'密码长度请大于或等于'.$ctlObj->setting['pwlength']);
			}
		}
		if(isset($ctlObj->setting['strongpw']) && $ctlObj->setting['strongpw']) {
			$strongpw_str = array();
			if(in_array(1, $ctlObj->setting['strongpw']) && !preg_match("/\d+/", $password)) {
				$strongpw_str[] = '数字';
			}
			if(in_array(2, $ctlObj->setting['strongpw']) && !preg_match("/[a-z]+/", $password)) {
				$strongpw_str[] = '小写字母';
			}
			if(in_array(3, $ctlObj->setting['strongpw']) && !preg_match("/[A-Z]+/", $password)) {
				$strongpw_str[] = '大写字母';
			}
			if(in_array(4, $ctlObj->setting['strongpw']) && !preg_match("/[^a-zA-Z0-9]+/", $password)) {
				$strongpw_str[] = '字母和数字';
			}
			if($strongpw_str) {
				return_status(302014,'密码复杂度不符合要求，必须包含('.join(',',$strongpw_str).')');
			}
		}
		// if(!isset($_G['setting']['mobile']['mobileregister']) || !$_G['setting']['mobile']['mobileregister']){
		// 	return_status(302015,'手机端暂时不允许注册');
		// }

		if(!$ctlObj->setting['regclosed'] && (!$ctlObj->setting['regstatus'] || !$ctlObj->setting['ucactivation'])) {
			if(!$ctlObj->setting['regstatus']) {
				return_status(302016,'系统暂时不允许注册');
			}
		}
		if($ctlObj->setting['regverify']) {
			if($ctlObj->setting['areaverifywhite']) {
				$location = $whitearea = '';
				$location = trim(convertip($clientip, "./"));
				if($location) {
					$whitearea = preg_quote(trim($ctlObj->setting['areaverifywhite']), '/');
					$whitearea = str_replace(array("\\*"), array('.*'), $whitearea);
					$whitearea = '.*'.$whitearea.'.*';
					$whitearea = '/^('.str_replace(array("\r\n", ' '), array('.*|.*', ''), $whitearea).')$/i';
					if(@preg_match($whitearea, $location)) {
						$ctlObj->setting['regverify'] = 0;
					}
				}
			}
		
			if($_G['cache']['ipctrl']['ipverifywhite']) {
				foreach(explode("\n", $_G['cache']['ipctrl']['ipverifywhite']) as $ctrlip) {
					if(preg_match("/^(".preg_quote(($ctrlip = trim($ctrlip)), '/').")/", $clientip)) {
						$ctlObj->setting['regverify'] = 0;
						break;
					}
				}
			}
		}
		if($ctlObj->setting['regverify']) {
			$groupinfo['groupid'] = 8;
		} else {
			$groupinfo['groupid'] = $ctlObj->setting['newusergroupid'];
		}
		if(!$password || $password != addslashes($password)) {
			return_status(302017,'密码有非法字符');
		}
		$censorexp = '/^('.str_replace(array('\\*', "\r\n", ' '), array('.*', '|', ''), 
				preg_quote(($ctlObj->setting['censoruser'] = trim($ctlObj->setting['censoruser'])), '/')).')$/i';
		if($ctlObj->setting['censoruser'] && @preg_match($censorexp, $username)) {
			return_status(302018,'不允许的用户名，请更换');
		}

		if($ctlObj->setting['regctrl']) {
			if(C::t('common_regip')->count_by_ip_dateline($clientip, $_G['timestamp']-$ctlObj->setting['regctrl']*3600)) {
				return_status(30201901,'该IP被封');
			}
		}
		
		$setregip = null;
		if($ctlObj->setting['regfloodctrl']) {
			$regip = C::t('common_regip')->fetch_by_ip_dateline($clientip, $_G['timestamp']-86400);
			if($regip) {
				if($regip['count'] >= $ctlObj->setting['regfloodctrl']) {
					return_status(30201902,'该IP被封，请明日再试');
				} else {
					$setregip = 1;
				}
			} else {
				$setregip = 2;
			}
		}
		$uid = uc_user_register($username, $password, $email, '', '', $clientip);
		if($uid <= 0) {
			if($uid == -1) {
				return_status(302101,'用户名不合法');
			} elseif($uid == -2) {
				return_status(302102,'用户名包含非法字符');
			} elseif($uid == -3) {
				return_status(302103,'用户名已经存在');
			} elseif($uid == -4) {
				return_status(302104,'Email格式有误');
			} elseif($uid == -5) {
				return_status(302105,'Email不允许注册');
			} elseif($uid == -6) {
				return_status(302106,'该Email已经被注册');
			}
		}
		$_G['username'] = $username;
		$password = md5(random(10));
		if($setregip !== null) {
			if($setregip == 1) {
				C::t('common_regip')->update_count_by_ip($clientip);
			} else {
				C::t('common_regip')->insert(array('ip' => $clientip, 'count' => 1, 'dateline' => $_G['timestamp']));
			}
		}
		$profile = $verifyarr = array ();
		$emailstatus = 0;
		$init_arr = array('credits' => explode(',', $ctlObj->setting['initcredits']), 'profile'=>$profile, 'emailstatus' => $emailstatus);
		C::t('common_member')->insert($uid, $username, $password, $email, $clientip, $groupinfo['groupid'], $init_arr);
		if($ctlObj->setting['regctrl'] || $ctlObj->setting['regfloodctrl']) {
			C::t('common_regip')->delete_by_dateline($_G['timestamp']-($ctlObj->setting['regctrl'] > 72 ? $ctlObj->setting['regctrl'] : 72)*3600);
			if($ctlObj->setting['regctrl']) {
				C::t('common_regip')->insert(array('ip' => $clientip, 'count' => -1, 'dateline' => $_G['timestamp']));
			}
		}
		if($ctlObj->setting['regverify'] == 1) {
			// $hashstr = urlencode(authcode("$email\t$_G[timestamp]", 'ENCODE', $_G['config']['security']['authkey']));
			// $registerurl = "{$_G[siteurl]}member.php?mod=".$this->setting['regname']."&amp;hash={$hashstr}&amp;email={$email}";
			// $idstring = random(6);
			// $authstr = $ctlObj->setting['regverify'] == 1 ? "$_G[timestamp]\t2\t$idstring" : '';
			// C::t('common_member_field_forum')->update($uid, array('authstr' => $authstr));
			// $verifyurl = "{$_G['setting']['siteurl']}member.php?mod=activate&amp;uid=$uid&amp;id=$idstring";

			$hash = authcode("$uid\t$email\t$_G[timestamp]", 'ENCODE', md5(substr(md5($_G['config']['security']['authkey']), 0, 16)));
			$verifyurl = $_G['setting']['siteurl'].'home.php?mod=misc&amp;ac=emailcheck&amp;hash='.urlencode($hash);

			$email_verify_message = lang('email', 'email_verify_message', array(
						'username' => $username,
						'bbname' => $ctlObj->setting['bbname'],
						'siteurl' => $_G['setting']['siteurl'],
						'url' => $verifyurl
						));
			if(!sendmail("$username <$email>", lang('email', 'email_verify_subject'), $email_verify_message)) {
				runlog('sendmail', "$email sendmail failed.");
				return_status(3021102,'邮件发送失败');
			}else{
				return_status(3021101,'邮件已发送，请登录邮箱验证');
			}
		}
		require_once libfile('cache/userstats', 'function');
		build_cache_userstats();
		$regmessage = dhtmlspecialchars('from bbssdk client');
		if($ctlObj->setting['regverify'] == 2) {
			C::t('common_member_validate')->insert(array(
						'uid' => $uid,
						'submitdate' => $_G['timestamp'],
						'moddate' => 0,
						'admin' => '',
						'submittimes' => 1,
						'status' => 0,
						'message' => $regmessage,
						'remark' => '',
						), false, true);
			manage_addnotify('verifyuser');
			return_status(3021103,'信息提交成功还需要人工审核，请联系管理员');
		}
		setloginstatus(array(
					'uid' => $uid,
					'username' => $_G['username'],
					'password' => $password,
					'groupid' => $groupinfo['groupid'],
					), 0);
		include_once libfile('function/stat');
		updatestat('register');
		checkfollowfeed();
		C::t('common_member_status')->update($uid, array('lastip' => $clientip, 'lastvisit' =>TIMESTAMP, 'lastactivity' => TIMESTAMP));
		//注册成功
		$userinfo =  C::t('common_member')->fetch_all_stat_memberlist($username);
		$member = C::t('common_member')->fetch_by_username($username);
		$userinfo = array_merge($userinfo[$uid],$member);
		$final = $this->relation_item($userinfo);
		$this->success_result($final);
	}

	public function get_lostpasswd()
	{
		global $_G;
		$username = urldecode($this->username);
		$email = strtolower(trim(urldecode($this->email)));
		$clientip = urldecode($this->clientip);

		if(empty($username) || empty($email) || empty($clientip) ){
			return_status(403);
		}

		loaducenter();
		if($username) {
			list($tmp['uid'], , $tmp['email']) = uc_get_user(addslashes($username));
			$tmp['email'] = strtolower(trim($tmp['email']));
			if($email != $tmp['email']) {
				return_status(301101,'找回密码，提交的用户信息错误');
			}
			$member = getuserbyuid($tmp['uid'], 1);
		} else {
			$emailcount = C::t('common_member')->count_by_email($email, 1);
			if(!$emailcount) {
				return_status(301102,'邮箱不存在');
			}
			if($emailcount > 1) {
				return_status(301103,'提交的邮箱存在多用户使用，不能发送邮件');
			}
			$member = C::t('common_member')->fetch_by_email($email, 1);
			list($tmp['uid'], , $tmp['email']) = uc_get_user(addslashes($member['username']));
			$tmp['email'] = strtolower(trim($tmp['email']));
		}
		if(!$member) {
			return_status(301101,'找回密码，提交的用户信息错误');
		} elseif($member['adminid'] == 1 || $member['adminid'] == 2) {
			return_status(301104,'管理员用户不能通过手机端找回密码');
		}

		$table_ext = $member['_inarchive'] ? '_archive' : '';
		if($member['email'] != $tmp['email']) {
			C::t('common_member'.$table_ext)->update($tmp['uid'], array('email' => $tmp['email']));
		}

		$idstring = random(6);
		C::t('common_member_field_forum'.$table_ext)->update($member['uid'], array('authstr' => "$_G[timestamp]\t1\t$idstring"));
		require_once libfile('function/mail');
		$_G['siteurl'] = $_G['setting']['siteurl'];
		$get_passwd_subject = lang('email', 'get_passwd_subject');
		$get_passwd_message = lang(
			'email',
			'get_passwd_message',
			array(
				'username' => $member['username'],
				'bbname' => $_G['setting']['bbname'],
				'siteurl' => $_G['setting']['siteurl'],
				'uid' => $member['uid'],
				'idstring' => $idstring,
				'clientip' => $clientip,
				'sign' => make_getpws_sign($member['uid'], $idstring),
			)
		);
		if(!sendmail("$username <$tmp[email]>", $get_passwd_subject, $get_passwd_message)) {
			return_status(3021102,'邮件发送失败');
		}
		return_status(200,'邮件发送成功');
	}

	public function get_resendmail()
	{
		global $_G;
		$username = urldecode($this->username);
		$email = strtolower(trim(urldecode($this->email)));
		$clientip = urldecode($this->clientip);

		if(empty($username) || empty($email) || empty($clientip) ){
			return_status(403);
		}

		loaducenter();
		if($username) {
			list($tmp['uid'], , $tmp['email']) = uc_get_user(addslashes($username));
			$tmp['email'] = strtolower(trim($tmp['email']));
			if($email != $tmp['email']) {
				return_status(301101,'重发邮件，提交的用户信息错误');
			}
			$member = getuserbyuid($tmp['uid'], 1);
		} else {
			$emailcount = C::t('common_member')->count_by_email($email, 1);
			if(!$emailcount) {
				return_status(301102,'邮箱不存在');
			}
			if($emailcount > 1) {
				return_status(301103,'提交的邮箱存在多用户使用，不能发送邮件');
			}
			$member = C::t('common_member')->fetch_by_email($email, 1);
			list($tmp['uid'], , $tmp['email']) = uc_get_user(addslashes($member['username']));
			$tmp['email'] = strtolower(trim($tmp['email']));
		}
		if(!$member) {
			return_status(301101,'重发邮件，提交的用户信息错误');
		}

		$uid = $tmp['uid'];
		$ctlObj = new register_ctl();
		$ctlObj->setting = $_G['setting'];
		
		// $idstring = random(6);
		// $authstr = $ctlObj->setting['regverify'] == 1 ? "$_G[timestamp]\t2\t$idstring" : '';
		// C::t('common_member_field_forum')->update($uid, array('authstr' => $authstr));
		// $verifyurl = "{$_G['setting']['siteurl']}member.php?mod=activate&amp;uid=$uid&amp;id=$idstring";

		$hash = authcode("$uid\t$email\t$_G[timestamp]", 'ENCODE', md5(substr(md5($_G['config']['security']['authkey']), 0, 16)));
		$verifyurl = $_G['setting']['siteurl'].'home.php?mod=misc&amp;ac=emailcheck&amp;hash='.urlencode($hash);

		$email_verify_message = lang('email', 'email_verify_message', array(
					'username' => $username,
					'bbname' => $ctlObj->setting['bbname'],
					'siteurl' => $_G['setting']['siteurl'],
					'url' => $verifyurl
					));
		if(!sendmail("$username <$email>", lang('email', 'email_verify_subject'), $email_verify_message)) {
			runlog('sendmail', "$email sendmail failed.");
			return_status(3021102,'邮件发送失败');
		}else{
			return_status(3021101,'邮件已发送，请登录邮箱验证');
		}
	}

	public function put_profile()
	{
		$uid = intval($this->uid);
		$clientip = urldecode($this->clientip);
		$gender = $this->gender;
		$avatar_big = urldecode($this->avatar_big);
		$avatar_middle = urldecode($this->avatar_middle);
		$avatar_small = urldecode($this->avatar_small);

		if(!$uid || !$clientip || $gender>2)
			return_status(403);

		if(!isset($gender) && !(!empty($avatar_big) && !empty($avatar_middle) && !empty($avatar_small)))
			return_status(403);

		loaducenter();
		$member = getuserbyuid($uid, 1);

		if(!$member){
			return_status(405,'不存在的用户');
		}

		$error_arr = array();
		$success_msg = array();

		if(isset($gender))
		{
			$gender = intval($gender)>2 ? 0 : intval($gender);
			if(DB::query("update ".DB::table('common_member_profile')." set gender=$gender where uid=$uid")){
				array_push($success_msg, '性别更新成功');
			}
		}
		if(!empty($avatar_big) && !empty($avatar_middle) && !empty($avatar_small))
		{
			$uc_input = uc_api_input("uid=$uid");
			$uc_avatarurl = UC_API.'/index.php?m=user&inajax=1&a=rectavatar&appid='.UC_APPID.'&input='.$uc_input.'&agent='.md5($_SERVER['HTTP_USER_AGENT']).'&avatartype=virtual';
			$post_data = array(
				'urlReaderTS' => (int) microtime(true)*1000,
				'avatar1' => flashdata_encode(file_get_contents($avatar_big)),
				'avatar2' => flashdata_encode(file_get_contents($avatar_middle)),
				'avatar3' => flashdata_encode(file_get_contents($avatar_small))
			);
			$response = push_http_query($uc_avatarurl,$post_data,'rectavatar');
			if(!preg_match("%success=\"1\"%is", $response)){
				write_log($uc_avatarurl.'###post_data=>##'.json_encode($post_data).'###response=>##'.$response);
				array_push($error_arr, '更新头像失败');
			}else{
				array_push($success_msg, '更新头像成功');
			}
		}
		if(count($error_arr)>0) {
			return_status(405,join(',',$error_arr));
		}else{
			$username = $member['username'];
			$userinfo =  C::t('common_member')->fetch_all_stat_memberlist($username);
			$member = C::t('common_member')->fetch_by_username($username);
			$userinfo = array_merge($userinfo[$uid],$member);
			$final = $this->relation_item($userinfo);
			$this->success_result($final,join(',',$success_msg));
		}
	}
        //version2.0
        public function post_profile()
	{
            global $_G;
            $uid = intval($this->uid);
            $clientip = urldecode($this->clientip);
            $gender = $this->gender;
            $avatar_big = urldecode($this->avatar_big);
            $avatar_middle = urldecode($this->avatar_middle);
            $avatar_small = urldecode($this->avatar_small);
            $sightml = urldecode($this->sightml);
            $birthday = $this->birthday;
            $residence = trim(urldecode($this->residence));
            
            if(!$uid || !$clientip || $gender>2)
                    return_status(403);

            if(!isset($gender) && !(!empty($avatar_big) && !empty($avatar_middle) && !empty($avatar_small))&&!isset($sightml)&&!isset($birthday)&&!isset($residence))
                    return_status(403,'没有内容被修改');

            loaducenter();
            $member = getuserbyuid($uid, 1);
            if(!$member){
                    return_status(405,'不存在的用户');
            }
            if($birthday&&(!strtotime($birthday)||(strtotime($birthday)&&!strpos($birthday, '-')))){
                return_status(403,'生日格式错误');
            }
            $setarr = array();
            if($residence){
                $rArr = explode('-', $residence);
                $setarr['resideprovince'] = isset($rArr[0])?$rArr[0]:'';
                $setarr['residecity'] = isset($rArr[1])?$rArr[1]:'';
                $setarr['residedist'] = isset($rArr[2])?$rArr[2]:'';
                $setarr['residecommunity'] = isset($rArr[3])?$rArr[3]:'';
            }
            
            $error_arr = array();
            $success_msg = array();

            if(isset($gender))
            {
                    $gender = intval($gender)>2 ? 0 : intval($gender);
                    if(DB::query("update ".DB::table('common_member_profile')." set gender=$gender where uid=$uid")){
                            array_push($success_msg, '性别更新成功');
                    }
            }
            if(!empty($avatar_big) && !empty($avatar_middle) && !empty($avatar_small))
            {
                    $uc_input = uc_api_input("uid=$uid");
                    $uc_avatarurl = UC_API.'/index.php?m=user&inajax=1&a=rectavatar&appid='.UC_APPID.'&input='.$uc_input.'&agent='.md5($_SERVER['HTTP_USER_AGENT']).'&avatartype=virtual';
                    $post_data = array(
                            'urlReaderTS' => (int) microtime(true)*1000,
                            'avatar1' => flashdata_encode(file_get_contents($avatar_big)),
                            'avatar2' => flashdata_encode(file_get_contents($avatar_middle)),
                            'avatar3' => flashdata_encode(file_get_contents($avatar_small))
                    );
                    $response = push_http_query($uc_avatarurl,$post_data,'rectavatar');
                    if(!preg_match("%success=\"1\"%is", $response)){
                            write_log($uc_avatarurl.'###post_data=>##'.json_encode($post_data).'###response=>##'.$response);
                            array_push($error_arr, '更新头像失败');
                    }else{
                            array_push($success_msg, '更新头像成功');
                    }
            }
            $forum = array();
            if($sightml){
                loadcache(array('smilies', 'smileytypes'));
                //$sightml = cutstr($sightml, $_G['group']['maxsigsize'], '');
                foreach($_G['cache']['smilies']['replacearray'] AS $skey => $smiley) {
                        $_G['cache']['smilies']['replacearray'][$skey] = '[img]'.$_G['setting']['siteurl'].'static/image/smiley/'.$_G['cache']['smileytypes'][$_G['cache']['smilies']['typearray'][$skey]]['directory'].'/'.$smiley.'[/img]';
                }
                $sightml = preg_replace($_G['cache']['smilies']['searcharray'], $_G['cache']['smilies']['replacearray'], trim($sightml));
                $forum['sightml'] = discuzcode($sightml, 1, 0, 0, 0, $_G['group']['allowsigbbcode'], $_G['group']['allowsigimgcode'], 0, 0, 1);;
//                if(!$_G['group']['maxsigsize']) {
//                        $forum['sightml'] = '';
//                }
                C::t('common_member_field_forum')->update($uid, $forum);
            }
            if($birthday){
                $b = explode('-', $birthday);
                $setarr['constellation'] = get_constellation($b[1], $b[2]);
                $setarr['zodiac'] = get_zodiac($b[0]);
                $setarr['birthyear']  = $b[0];
                $setarr['birthmonth'] = $b[1];
                $setarr['birthday']   = $b[2];
            }
            if($setarr){
                C::t('common_member_profile')->update($uid, $setarr);
            }
            if(count($error_arr)>0) {
                    return_status(405,join(',',$error_arr));
            }else{
                    $username = $member['username'];
                    $userinfo =  C::t('common_member')->fetch_all_stat_memberlist($username);
                    $member = C::t('common_member')->fetch_by_username($username);
                    $userinfo = array_merge($userinfo[$uid],$member);
                    space_merge($userinfo, 'field_forum');
                    space_merge($userinfo, 'profile');
                    $final = $this->relation_item($userinfo);
                    $this->success_result($final,join(',',$success_msg));
            }
	}
        public function post_support(){
            global $_G;
            $_G['uid'] = intval($this->uid);
            $_G['tid'] = intval($this->tid);
            $_G['pid'] = intval($this->pid);
            $do = $this->do?$this->do:'support';
            
            if(empty($_G['uid'])){
                return_status(601);
            }
            $doArray = array('support', 'against');

            $post = C::t('forum_post')->fetch('tid:'.$_G['tid'], $_G['pid'], false);

            if(!in_array($do, $doArray) || empty($post) || $post['first'] == 1 || ($_G['setting']['threadfilternum'] && $_G['setting']['filterednovote'] && getstatus($post['status'], 11))) {
                    return_status(602);
            }

            $hotreply = C::t('forum_hotreply_number')->fetch_by_pid($post['pid']);
            if($_G['uid'] == $post['authorid']) {
                    return_status(603);
            }

            if(empty($hotreply)) {
                    $hotreply['pid'] = C::t('forum_hotreply_number')->insert(array(
                            'pid' => $post['pid'],
                            'tid' => $post['tid'],
                            'support' => 0,
                            'against' => 0,
                            'total' => 0,
                    ), true);
            } else {
                    if(C::t('forum_hotreply_member')->fetch($post['pid'], $_G['uid'])) {
                            return_status(604);
                    }
            }

            $typeid = $do == 'support' ? 1 : 0;

            C::t('forum_hotreply_number')->update_num($post['pid'], $typeid);
            C::t('forum_hotreply_member')->insert(array(
                    'tid' => $post['tid'],
                    'pid' => $post['pid'],
                    'uid' => $_G['uid'],
                    'attitude' => $typeid,
            ));

            $hotreply[$do]++;

            return_status(200,'操作成功');
        }
        public function post_recommend(){
            require_once libfile('function/forum');
            global $_G;
            $_G['uid'] = intval($this->uid);
            $_G['tid'] = intval($this->tid);
            $_GET['do'] = $do = $this->do?$this->do:'add';
            
            if(empty($_G['uid'])){
                return_status(601);
            }
            $thread = C::t('forum_thread')->fetch($_G['tid']);
            
            if(!$thread) {
		return_status(612);
            }
            loadcache('setting');
            if($thread['authorid'] == $_G['uid'] && !$_G['setting']['recommendthread']['ownthread']) {
                    return_status(613);
            }
            if(C::t('forum_memberrecommend')->fetch_by_recommenduid_tid($_G['uid'], $_G['tid'])) {
                    return_status(614);
            }

            $recommendcount = C::t('forum_memberrecommend')->count_by_recommenduid_dateline($_G['uid'], $_G['timestamp']-86400);
            if($_G['setting']['recommendthread']['daycount'] && $recommendcount >= $_G['setting']['recommendthread']['daycount']) {
                    return_status(615);
            }

            $_G['group']['allowrecommend'] = intval($_GET['do'] == 'add' ? $_G['group']['allowrecommend'] : -$_G['group']['allowrecommend']);
            $fieldarr = array();
            if($_GET['do'] == 'add') {
                    $heatadd = 'recommend_add=recommend_add+1';
                    $fieldarr['recommend_add'] = 1;
            } else {
                    $heatadd = 'recommend_sub=recommend_sub+1';
                    $fieldarr['recommend_sub'] = 1;
            }

            update_threadpartake($_G['tid']);
            $fieldarr['heats'] = 0;
            $fieldarr['recommends'] = $_G['group']['allowrecommend'];
            C::t('forum_thread')->increase($_G['tid'], $fieldarr);
            C::t('forum_thread')->update($_G['tid'], array('lastpost' => TIMESTAMP));
            C::t('forum_memberrecommend')->insert(array('tid'=>$_G['tid'], 'recommenduid'=>$_G['uid'], 'dateline'=>$_G['timestamp']));
            
            $data['recommend_add'] = intval($_GET['do'] == 'add'?$thread['recommend_add']+1:$thread['recommend_add']);
            $data['recommend_sub'] = intval($_GET['do'] == 'add'?$thread['recommend_sub']:$thread['recommend_sub']-1);
            $data['recommends'] = intval($_GET['do'] == 'add'?$thread['recommends']+1:$thread['recommends']-1);
            $this->success_result($data);
        }
        public function post_follow(){
            global $_G;
            $_G['uid']  = intval($this->uid);
            $_G['fuid'] = intval($this->fuid);
            
            if(empty($_G['uid'])) {
                    return_status(601);
            }
            
            $followuid = intval($_G['fuid']);
            if(empty($followuid)) {
                    return_status(607);
            }
            if($_G['uid'] == $followuid) {
                    return_status(608);
            }
            $special = intval($this->special) ? intval($this->special) : 0;
            $followuser = getuserbyuid($followuid);
            $mutual = 0;
            $followed = C::t('home_follow')->fetch_by_uid_followuid($followuid, $_G['uid']);
            if(!empty($followed)) {
                    if($followed['status'] == '-1') {
                            return_status(609);
                    }
                    $mutual = 1;
                    C::t('home_follow')->update_by_uid_followuid($followuid, $_G['uid'], array('mutual'=>1));
            }
            $followed = C::t('home_follow')->fetch_by_uid_followuid($_G['uid'], $followuid);
            if(empty($followed)) {
                    $user = getuserbyuid($_G['uid']);
                    $followdata = array(
                            'uid' =>$_G['uid'],
                            'username' => $user['username'],
                            'followuid' => $followuid,
                            'fusername' => $followuser['username'],
                            'status' => 0,
                            'mutual' => $mutual,
                            'dateline' => TIMESTAMP
                    );
                    C::t('home_follow')->insert($followdata, false, true);
                    C::t('common_member_count')->increase($_G['uid'], array('following' => 1));
                    C::t('common_member_count')->increase($followuid, array('follower' => 1, 'newfollower' => 1));
                    notification_add($followuid, 'follower', 'member_follow_add', array('count' => $count, 'from_id'=>$_G['uid'], 'from_idtype' => 'following'), 1);
            } elseif($special) {
                    $status = $special == 1 ? 1 : 0;
                    C::t('home_follow')->update_by_uid_followuid($_G['uid'], $followuid, array('status'=>$status));
                    $special = $special == 1 ? 2 : 1;
            } else {
                    return_status(610);
            }
            $type = !$special ? 'add' : 'special';
            
            return_status(200,'成功收听');
        }
        public function post_unfollow(){
            global $_G;
            $_G['uid']  = intval($this->uid);
            $_G['fuid'] = intval($this->fuid);
            
            if(empty($_G['uid'])) {
                    return_status(601);
            }
            
            $delfollowuid = intval($_G['fuid']);
            if(empty($delfollowuid)) {
                    return_status(607);
            }
            $affectedrows = C::t('home_follow')->delete_by_uid_followuid($_G['uid'], $delfollowuid);
            if($affectedrows) {
                    C::t('home_follow')->update_by_uid_followuid($delfollowuid, $_G['uid'], array('mutual'=>0));
                    C::t('common_member_count')->increase($_G['uid'], array('following' => -1));
                    C::t('common_member_count')->increase($delfollowuid, array('follower' => -1, 'newfollower' => -1));
            }
            return_status(200,'成功取消关注');
        }
        public function get_my(){
            global $_G;
            
            $_GET['pagesize'] = intval($_GET['pagesize']);
            $_GET['page'] = intval($_GET['page']);
            $_GET['fid']  = intval($_GET['fid']);
            $_GET['fid']  = isset($_GET['fid'])?$_GET['fid']:0;
            $_GET['page'] = !isset($_GET['page'])||$_GET['page']<1?1:$_GET['page'];
            $perpage = !isset($_GET['pagesize'])||$_GET['pagesize']<1?10:$_GET['pagesize'];
            $start = $perpage * ($_GET['page'] - 1);
            $data = array();
            $_G['uid'] = intval($_GET['uid']);
            if(!$_G['uid']) {
                    return_status(601);
            }
            $lang = lang('forum/template');
            $filter_array = array( 'common' => $lang['have_posted'], 'save' => $lang['guide_draft'], 'close' => $lang['close'], 'aduit' => $lang['pending'], 'ignored' => $lang['have_ignored'], 'recyclebin' => $lang['forum_recyclebin']);
            $viewtype = in_array($_GET['type'], array('reply', 'thread', 'postcomment')) ? $_GET['type'] : 'thread';
            if($searchkey = stripsearchkey($_GET['searchkey'])) {
                    $searchkey = dhtmlspecialchars($searchkey);
            }
            $theurl .= '&type='.$viewtype;
            $filter = in_array($_GET['filter'], array_keys($filter_array)) ? $_GET['filter'] : '';
            $searchbody = 0;
            if($filter) {
                    $theurl .= '&filter='.$filter;
                    $searchbody = 1;
            }
            if($_GET['fid']) {
                    $theurl .= '&fid='.intval($_GET['fid']);
                    $searchbody = 1;
            }
            if($searchkey) {
                    $theurl .= '&searchkey='.$searchkey;
                    $searchbody = 1;
            }
            //require_once libfile('function/forumlist');
            //$forumlist = forumselect(FALSE, 0, intval($_GET['fid']));
            $list = get_my_threads($viewtype, $_GET['fid'], $filter, $searchkey, $start, $perpage, $theurl);
            $data['total_count'] = '';
            $data['pagesize'] = $perpage;
            $data['currpage'] = $_GET['page'];
            $data['nextpage'] = $_GET['page']+1;
            $data['prepage'] = $_GET['page']>1?$_GET['page']-1:1;

            $data['list'] = array_values($list['threadlist']);
            $this->success_result($data);
        }

        private function relation_item($item)
	{
                space_merge($item, 'field_forum');
                space_merge($item, 'profile');
		return array(
			'uid' => (int)$item['uid'],
			'gender' => (int) $item['gender'],
			'email' => $item['email'],
			'username' => $item['username'],
			'password' => $item['password'],
			'avatar' => avatar($item['uid'],'big',1),
			// 'avatar' => array(avatar($item['uid'],'big',1),avatar($item['uid'],'middle',1),avatar($item['uid'],'small',1)),
			'status' => (int)$item['status'],
			'emailstatus' => (int)$item['emailstatus'],
			'avatarstatus' => (int)$item['avatarstatus'],
			'videophotostatus' => (int)$item['videophotostatus'],
			'adminid' => (int)$item['adminid'],
			'groupid' => (int)$item['groupid'],
			'groupexpiry' => (int)$item['groupexpiry'],
			'extgroupids' => $item['extgroupids'],
			'regdate' => (int)$item['regdate'],
			'credits' => (int)$item['credits'],
			'notifysound' => (int)$item['notifysound'],
			'timeoffset' => (int)$item['timeoffset'],
			'newpm' => (int) $item['newpm'],
			'newprompt' => (int) $item['newprompt'],
			'accessmasks' => (int) $item['accessmasks'],
			'allowadmincp' => (int) $item['allowadmincp'],
			'onlyacceptfriendpm' => (int) $item['onlyacceptfriendpm'],
			'conisbind' => (int) $item['conisbind'],
                        'sightml' => $item['sightml'],
                        'birthyear' => $item['birthyear'],
                        'birthmonth' => $item['birthmonth'],
                        'birthday' => $item['birthday'],
                        'resideprovince' => $item['resideprovince'],
                        'residecity' => $item['residecity'],
                        'residedist' => $item['residedist'],
                        'residecommunity' => $item['residecommunity'],
                        'residesuite' => $item['residesuite'],
		);
	}
}

function get_my_threads($viewtype, $fid = 0, $filter = '', $searchkey = '', $start = 0, $perpage = 20, $theurl = '') {
        global $_G;
        $fid = $fid ? intval($fid) : null;
        loadcache('forums');
        $dglue = '=';
        if($viewtype == 'thread') {
                $authorid = $_G['uid'];
                $dglue = '=';
                if($filter == 'recyclebin') {
                        $displayorder = -1;
                } elseif($filter == 'aduit') {
                        $displayorder = -2;
                } elseif($filter == 'ignored') {
                        $displayorder = -3;
                } elseif($filter == 'save') {
                        $displayorder = -4;
                } elseif($filter == 'close') {
                        $closed = 1;
                } elseif($filter == 'common') {
                        $closed = 0;
                        $displayorder = 0;
                        $dglue = '>=';
                }

                $gids = $fids = $forums = array();

                foreach(C::t('forum_thread')->fetch_all_by_authorid_displayorder($authorid, $displayorder, $dglue, $closed, $searchkey, $start, $perpage, null, $fid) as $tid => $value) {
                        if(!isset($_G['cache']['forums'][$value['fid']])) {
                                $gids[$value['fid']] = $value['fid'];
                        } else {
                                $forumnames[$value['fid']] = array('fid'=> $value['fid'], 'name' => $_G['cache']['forums'][$value['fid']]['name']);
                        }
                        $list[$value['tid']] = guide_procthread($value);
                }

                if(!empty($gids)) {
                        $gforumnames = C::t('forum_forum')->fetch_all_name_by_fid($gids);
                        foreach($gforumnames as $fid => $val) {
                                $forumnames[$fid] = $val;
                        }
                }
                $listcount = count($list);
        } elseif($viewtype == 'postcomment') {
                require_once libfile('function/post');
                $pids = $tids = array();
                $postcommentarr = C::t('forum_postcomment')->fetch_all_by_authorid($_G['uid'], $start, $perpage);
                foreach($postcommentarr as $value) {
                        $pids[] = $value['pid'];
                        $tids[] = $value['tid'];
                }
                $pids = C::t('forum_post')->fetch_all(0, $pids);
                $tids = C::t('forum_thread')->fetch_all($tids);

                $list = $fids = array();
                foreach($postcommentarr as $value) {
                        $value['authorid'] = $pids[$value['pid']]['authorid'];
                        $value['fid'] = $pids[$value['pid']]['fid'];
                        $value['invisible'] = $pids[$value['pid']]['invisible'];
                        $value['dateline'] = $pids[$value['pid']]['dateline'];
                        $value['message'] = $pids[$value['pid']]['message'];
                        $value['special'] = $tids[$value['tid']]['special'];
                        $value['status'] = $tids[$value['tid']]['status'];
                        $value['subject'] = $tids[$value['tid']]['subject'];
                        $value['digest'] = $tids[$value['tid']]['digest'];
                        $value['attachment'] = $tids[$value['tid']]['attachment'];
                        $value['replies'] = $tids[$value['tid']]['replies'];
                        $value['views'] = $tids[$value['tid']]['views'];
                        $value['lastposter'] = $tids[$value['tid']]['lastposter'];
                        $value['lastpost'] = $tids[$value['tid']]['lastpost'];
                        $value['icon'] = $tids[$value['tid']]['icon'];
                        $value['tid'] = $pids[$value['pid']]['tid'];

                        $fids[] = $value['fid'];
                        $value['comment'] = messagecutstr($value['comment'], 100);
                        $list[] = guide_procthread($value);
                }
                unset($pids, $tids, $postcommentarr);
                if($fids) {
                        $fids = array_unique($fids);
                        $forumnames = C::t('forum_forum')->fetch_all_name_by_fid($gids);
                }
                $listcount = count($list);
        } else {
                $invisible = null;

                if($filter == 'recyclebin') {
                        $invisible = -5;
                } elseif($filter == 'aduit') {
                        $invisible = -2;
                } elseif($filter == 'save' || $filter == 'ignored') {
                        $invisible = -3;
                        $displayorder = -4;
                } elseif($filter == 'close') {
                        $closed = 1;
                } elseif($filter == 'common') {
                        $invisible = 0;
                        $displayorder = 0;
                        $dglue = '>=';
                        $closed = 0;
                }
                require_once libfile('function/post');
                $posts = C::t('forum_post')->fetch_all_by_authorid(0, $_G['uid'], true, 'DESC', $start, $perpage, null, $invisible, $fid, $followfid);
                $listcount = count($posts);
                foreach($posts as $pid => $post) {
                        $tids[$post['tid']][] = $pid;
                        $post['message'] = !getstatus($post['status'], 2) || $post['authorid'] == $_G['uid'] ? messagecutstr($post['message'], 100) : '';
                        $posts[$pid] = $post;
                }
                if(!empty($tids)) {
                        $threads = C::t('forum_thread')->fetch_all_by_tid_displayorder(array_keys($tids), $displayorder, $dglue, array(), $closed);
                        foreach($threads as $tid => $thread) {
                                if(!isset($_G['cache']['forums'][$thread['fid']])) {
                                        $gids[$thread['fid']] = $thread['fid'];
                                } else {
                                        $forumnames[$thread[fid]] = array('fid' => $thread['fid'], 'name' => $_G['cache']['forums'][$thread[fid]]['name']);
                                }
                                $threads[$tid] = guide_procthread($thread);
                        }
                        if(!empty($gids)) {
                                $groupforums = C::t('forum_forum')->fetch_all_name_by_fid($gids);
                                foreach($groupforums as $fid => $val) {
                                        $forumnames[$fid] = $val;
                                }
                        }
                        $list = array();
                        foreach($tids as $key => $val) {
                                $list[$key] = $threads[$key];
                        }
                        unset($threads);
                }
        }
        $multi = simplepage($listcount, $perpage, $_G['page'], $theurl);
        return array('forumnames' => $forumnames, 'threadcount' => $listcount, 'threadlist' => $list, 'multi' => $multi, 'tids' => $tids, 'posts' => $posts);
}
function guide_procthread($thread) {
	global $_G;
	$todaytime = strtotime(dgmdate(TIMESTAMP, 'Ymd'));
	$thread['lastposterenc'] = rawurlencode($thread['lastposter']);
	$thread['multipage'] = '';
	$topicposts = $thread['special'] ? $thread['replies'] : $thread['replies'] + 1;
	if($topicposts > $_G['ppp']) {
		$pagelinks = '';
		$thread['pages'] = ceil($topicposts / $_G['ppp']);
		for($i = 2; $i <= 6 && $i <= $thread['pages']; $i++) {
			$pagelinks .= "<a href=\"forum.php?mod=viewthread&tid=$thread[tid]&amp;extra=$extra&amp;page=$i\">$i</a>";
		}
		if($thread['pages'] > 6) {
			$pagelinks .= "..<a href=\"forum.php?mod=viewthread&tid=$thread[tid]&amp;extra=$extra&amp;page=$thread[pages]\">$thread[pages]</a>";
		}
		$thread['multipage'] = '&nbsp;...'.$pagelinks;
	}

	if($thread['highlight']) {
		$string = sprintf('%02d', $thread['highlight']);
		$stylestr = sprintf('%03b', $string[0]);

		$thread['highlight'] = ' style="';
		$thread['highlight'] .= $stylestr[0] ? 'font-weight: bold;' : '';
		$thread['highlight'] .= $stylestr[1] ? 'font-style: italic;' : '';
		$thread['highlight'] .= $stylestr[2] ? 'text-decoration: underline;' : '';
		$thread['highlight'] .= $string[1] ? 'color: '.$_G['forum_colorarray'][$string[1]] : '';
		$thread['highlight'] .= '"';
	} else {
		$thread['highlight'] = '';
	}

	$thread['recommendicon'] = '';
	if(!empty($_G['setting']['recommendthread']['status']) && $thread['recommends']) {
		foreach($_G['setting']['recommendthread']['iconlevels'] as $k => $i) {
			if($thread['recommends'] > $i) {
				$thread['recommendicon'] = $k+1;
				break;
			}
		}
	}

	$thread['moved'] = $thread['heatlevel'] = $thread['new'] = 0;
	$thread['icontid'] = $thread['forumstick'] || !$thread['moved'] && $thread['isgroup'] != 1 ? $thread['tid'] : $thread['closed'];
	$thread['folder'] = 'common';
	$thread['weeknew'] = TIMESTAMP - 604800 <= $thread['dbdateline'];
	if($thread['replies'] > $thread['views']) {
		$thread['views'] = $thread['replies'];
	}
	if($_G['setting']['heatthread']['iconlevels']) {
		foreach($_G['setting']['heatthread']['iconlevels'] as $k => $i) {
			if($thread['heats'] > $i) {
				$thread['heatlevel'] = $k + 1;
				break;
			}
		}
	}
	$thread['istoday'] = $thread['dateline'] > $todaytime ? 1 : 0;
	$thread['dbdateline'] = $thread['dateline'];
	$thread['dateline'] = dgmdate($thread['dateline'], 'u', '9999', getglobal('setting/dateformat'));
	$thread['dblastpost'] = $thread['lastpost'];
	$thread['lastpost'] = dgmdate($thread['lastpost'], 'u');

	if(in_array($thread['displayorder'], array(1, 2, 3, 4))) {
		$thread['id'] = 'stickthread_'.$thread['tid'];
	} else {
		$thread['id'] = 'normalthread_'.$thread['tid'];
	}
	$thread['rushreply'] = getstatus($thread['status'], 3);
	return $thread;
}