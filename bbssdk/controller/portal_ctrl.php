<?php
if(!defined('DISABLEDEFENSE'))  exit('Access Denied!');

class Portal extends BaseCore
{
	function __construct()
	{
            parent::__construct();
	}
        function get_commentslist(){
            $page    = intval($_REQUEST['page']);
            $perpage = intval($_REQUEST['pagesize']);
            $id      = intval($_REQUEST['id']);
            $idtype  = $_REQUEST['aid']?$_REQUEST['aid']:'aid';
            
            $perpage = $perpage?$perpage:10;
            if($page<1) $page = 1;
            $start = ($page-1)*$perpage;

            $commentlist = array();
            $csubject = C::t('portal_article_count')->fetch($id);
            if($csubject['commentnum']) {
                $query = C::t('portal_comment')->fetch_all_by_id_idtype($id, $idtype, 'dateline', 'DESC', $start, $perpage);
                foreach($query as $value) {
                    $value['avatar'] = avatar($value['uid'],'middle',1);
                    $commentlist[] = $value;
                }
            }
            $data['total_count'] =  $csubject['commentnum'];
            $data['pagesize'] = $perpage;
            $data['currpage'] = $page;
            $total_page = ceil($data['total_count']/$perpage);
            $data['nextpage'] = $page+1 <= $total_page ? $page+1 : $total_page;
            $data['prepage'] = $page-1>0 ? $page-1 : 1;
            $data['list'] = $commentlist;
            $this->success_result($data);
        }
        function get_commentitem(){
            $cid  = intval($_REQUEST['cid']);
            $data = DB::fetch_first('SELECT * FROM %t WHERE cid = %d limit 1', array('portal_comment', $cid));
            $data['avatar'] = avatar($data['uid'],'middle',1);
            $this->success_result($data);
        }
        function post_deletecomment(){
            $uid  = intval($_REQUEST['uid']);
            $cid  = intval($_REQUEST['cid']);
            if(!$uid) return_status(403);
            $member = getuserbyuid($uid, 1);
            $mgroup = C::t('common_admingroup')->fetch_all_merge_usergroup($member['groupid']);
            $comment = C::t('portal_comment')->fetch($cid);

            if(empty($comment)) {
                return_status(802);
            }
            if((!$mgroup[$uid]['allowmanagearticle']&&$uid!=$comment['uid'])||$member['groupid']==7){
                return_status(803);
            }
            
            C::t('portal_comment')->delete($cid);
            $idtype = in_array($comment['idtype'], array('aid' ,'topicid')) ? $comment['idtype'] : 'aid';
            $tablename = $idtype == 'aid' ? 'portal_article_count' : 'portal_topic';
            C::t($tablename)->increase($comment[id], array('commentnum' => -1));
            return_status(200);
        }
        function post_addcomment(){
            global $_G;
            $uid  = intval($_REQUEST['uid']);
            $aid  = intval($_REQUEST['aid']);
            $message = $_REQUEST['message'];
            if(!$uid) return_status(403);
            $_G['uid'] = $uid;
            $member = getuserbyuid($uid, 1);
            $_G['username'] = $member['username'];
            C::app()->var['member'] = $member;
            $_G['groupid'] = $groupid = $member['groupid'];
            $groupid > 0 && $authAll = DB::fetch_all("select * from ".DB::table('common_usergroup')." a LEFT JOIN ".DB::table('common_usergroup_field')." b on a.groupid=b.groupid where a.groupid in($groupid)");
            count($authAll)>0 && C::app()->var['group'] = $authAll[0];
                        
            if(!checkperm('allowcommentarticle')) {
		return_status(804);
            }

            require_once libfile('function/spacecp');
            require_once libfile('function/home');
            require_once libfile('function/portalcp');

            if(!cknewuser(1)){
                return_status(806);
            }

            $waittime = interval_check('post');
            if($waittime > 0) {
                return_status(807,'等待'.$waittime.'秒再发');
            }
            
            $retmessage = $this->addportalarticlecomment($aid, $message, 'aid');
            if(is_int($retmessage)){
                $data = DB::fetch_first('SELECT * FROM %t WHERE cid = %d limit 1', array('portal_comment', $retmessage));
                $data['avatar'] = avatar($data['uid'],'middle',1);
                $article = $this->getdetail($data['id']);
                $data['commentnum'] = $article['commentnum'];
                $this->success_result($data);
            }
            if($retmessage == 'comment_comment_notallowed'){
                return_status(804);
            }
            return_status(805,$retmessage);
        }
        function addportalarticlecomment($id, $message, $idtype = 'aid') {
                global $_G;
                $id = intval($id);
                if(empty($id)) {
                        return 'comment_comment_noexist';
                }
                $message = getstr($message, $_G['group']['allowcommentarticle'], 0, 0, 1, 0);
                if(strlen($message) < 2) return 'content_is_too_short';

                $idtype = in_array($idtype, array('aid' ,'topicid')) ? $idtype : 'aid';
                $tablename = $idtype == 'aid' ? 'portal_article_title' : 'portal_topic';
                $data = C::t($tablename)->fetch($id);
                if(empty($data)) {
                        return 'comment_comment_noexist';
                }
                $catid = $data['catid'];
                $category = C::t('portal_category')->fetch($catid);
                if($data['allowcomment'] != 1||$category['allowcomment']!=1) {
                        return 'comment_comment_notallowed';
                }

                $message = censor($message);
                if(censormod($message)) {
                        $comment_status = 1;
                } else {
                        $comment_status = 0;
                }

                $setarr = array(
                        'uid' => $_G['uid'],
                        'username' => $_G['username'],
                        'id' => $id,
                        'idtype' => $idtype,
                        'postip' => $_G['clientip'],
                        'port' => $_G['remoteport'],
                        'dateline' => $_G['timestamp'],
                        'status' => $comment_status,
                        'message' => $message
                );

                $pcid = C::t('portal_comment')->insert($setarr, true);

                if($comment_status == 1) {
                        updatemoderate($idtype.'_cid', $pcid);
                        $notifykey = $idtype == 'aid' ? 'verifyacommont' : 'verifytopiccommont';
                        manage_addnotify($notifykey);
                }
                $tablename = $idtype == 'aid' ? 'portal_article_count' : 'portal_topic';
                C::t($tablename)->increase($id, array('commentnum' => 1));
                C::t('common_member_status')->update($_G['uid'], array('lastpost' => $_G['timestamp']), 'UNBUFFERED');

                if($data['uid'] != $_G['uid']) {
                        updatecreditbyaction('portalcomment', 0, array(), $idtype.$id);
                }
                return $pcid;
        }
        function post_editcomment(){
            require_once libfile('function/home');
            global $_G;
            $uid  = intval($_REQUEST['uid']);
            $cid  = intval($_REQUEST['cid']);
            $message = getstr($_REQUEST['message'], 0, 0, 0, 2);
            if(!$uid) return_status(403);
            
            $member = getuserbyuid($uid, 1);
            $mgroup = C::t('common_admingroup')->fetch_all_merge_usergroup($member['groupid']);
            $comment = C::t('portal_comment')->fetch($cid);
            
            $_G['uid'] = $uid;
            C::app()->var['member'] = $member;
            $_G['groupid'] = $groupid = $member['groupid'];
            $groupid > 0 && $authAll = DB::fetch_all("select * from ".DB::table('common_usergroup')." a LEFT JOIN ".DB::table('common_usergroup_field')." b on a.groupid=b.groupid where a.groupid in($groupid)");
            count($authAll)>0 && C::app()->var['group'] = $authAll[0];
            
            if(empty($comment)) {
                return_status(802);
            }

            if((!$mgroup[$uid]['allowmanagearticle']&&$uid!=$comment['uid'])||$member['groupid']==7){
                return_status(803);
            }
            
            if(strlen($message) < 2) return_status(805,'content_is_too_short');
            $message = censor($message);
            if(censormod($message)) {
                    $comment_status = 1;
            } else {
                    $comment_status = 0;
            }
            C::t('portal_comment')->update($comment['cid'], array('message' => $message, 'status' => $comment_status, 'postip' => $_G['clientip'], 'port' => $_G['remoteport']));
            $data = DB::fetch_first('SELECT * FROM %t WHERE cid = %d limit 1', array('portal_comment', $cid));
            $data['avatar'] = avatar($data['uid'],'middle',1);
            $this->success_result($data);

        }
        function post_click(){
            require_once libfile('function/home');
            require_once libfile('function/spacecp');
            
            $uid     = intval($_REQUEST['uid']);
            $id      = intval($_REQUEST['id']);
            $idtype  = $_REQUEST['idtype'];
            $clickid = intval($_REQUEST['clickid']);
            
            $idtype = in_array($idtype,array('aid','picid','blogid'))?$idtype:'aid';
            $clickid = $clickid?$clickid:1;
            $tablename = 'portal_article_title';
            $item = C::t('portal_article_title')->fetch($id);
            if(!$uid) return_status(403);
            
            $member = getuserbyuid($uid, 1);            
            $_G['uid'] = $uid;
            C::app()->var['member'] = $member;
            $_G['groupid'] = $groupid = $member['groupid'];
            $groupid > 0 && $authAll = DB::fetch_all("select * from ".DB::table('common_usergroup')." a LEFT JOIN ".DB::table('common_usergroup_field')." b on a.groupid=b.groupid where a.groupid in($groupid)");
            count($authAll)>0 && C::app()->var['group'] = $authAll[0];
            
            if(!checkperm('allowclick')) {
                    return_status(808);
            }

            if($item['uid'] == $_G['uid']) {
                    return_status(809);
            }

            if(isblacklist($item['uid'])) {
                    return_status(810);
            }

            if(C::t('home_clickuser')->count_by_uid_id_idtype($_G['uid'], $id, $idtype)) {
                    return_status(811);
            }

            $setarr = array(
                    'uid' => $_G['uid'],
                    'username' => $_G['username'],
                    'id' => $id,
                    'idtype' => $idtype,
                    'clickid' => $clickid,
                    'dateline' => $_G['timestamp']
            );
            C::t('home_clickuser')->insert($setarr);

            C::t($tablename)->update_click($id, $clickid, 1);
            
            hot_update($idtype, $id, $item['hotuser']);

            $q_note = '';
            $q_note_values = array();

            $fs = array();
            
            require_once libfile('function/portal');
            $article_url = fetch_article_url($item);
            $fs['title_template'] = 'feed_click_article';
            $fs['title_data'] = array(
                    'touser' => "<a href=\"home.php?mod=space&uid=$item[uid]\">{$item[username]}</a>",
                    'subject' => "<a href=\"$article_url\">$item[title]</a>",
                    'click' => $click['name']
            );

            $q_note = 'click_article';
            $q_note_values = array(
                    'url'=>$article_url,
                    'subject'=>$item['title'],
                    'from_id' => $item['aid'],
                    'from_idtype' => 'aid'
            );
                   

            if(empty($item['friend']) && ckprivacy('click', 'feed')) {
                    require_once libfile('function/feed');
                    $fs['title_data']['hash_data'] = "{$idtype}{$id}";
                    feed_add('click', $fs['title_template'], $fs['title_data'], '', array(), $fs['body_general'],$fs['images'], $fs['image_links']);
            }

            updatecreditbyaction('click', 0, array(), $idtype.$id);

            require_once libfile('function/stat');
            updatestat('click');

            notification_add($item['uid'], 'click', $q_note, $q_note_values);
            $item = C::t('portal_article_title')->fetch($id);
            $data['click1'] = $item['click1'];
            $data['click2'] = $item['click2'];
            $data['click3'] = $item['click3'];
            $data['click4'] = $item['click4'];
            $data['click5'] = $item['click5'];
            
            $this->success_result($data);
        }
        function get_list(){
            require_once libfile('function/home');
            require_once libfile('function/portalcp');
            
            $page     = intval($_REQUEST['page']);;
            $pagesize = intval($_REQUEST['pagesize']);
            $catid    = intval($_REQUEST['catid']);
            $list = array();
            
            $pagesize = $pagesize?$pagesize:20;
            $start = ($page-1)*$pagesize;
            if($start<0) $start = 0;
            
            $subcatids   = category_get_childids('portal', $catid);
            $subcatids[] = $catid;
            $wheresql    = "at.catid IN (".dimplode($subcatids).")";
            $count = C::t('portal_article_title')->fetch_all_by_sql($wheresql, '', 0, 0, 1, 'at');
            $query = C::t('portal_article_title')->fetch_all_by_sql($wheresql, 'ORDER BY at.dateline DESC', $start, $perpage, 0, 'at');
            foreach($query as $value) {
                $list[] = $this->getdetail($value['aid']);
            }
            $data['total_count'] =  $count;
            $data['pagesize'] = $pagesize;
            $data['currpage'] = $page;
            $total_page = ceil($data['total_count']/$pagesize);
            $data['nextpage'] = $page+1 <= $total_page ? $page+1 : $total_page;
            $data['prepage'] = $page-1>0 ? $page-1 : 1;
            $data['list'] = $list;
            $this->success_result($data);
        }
        function get_detail(){
            $aid     = intval($_REQUEST['aid']);
            $detail  = $this->getdetail($aid);
            $this->success_result($detail);
        }
        function getdetail($aid){
            global $_G;
            $content = C::t('portal_article_content')->fetch_by_aid_page($aid, 1);
            require_once libfile('function/blog');
            require_once libfile('function/portal');
            require_once libfile('function/home');
            $content['content'] = blog_bbcode($content['content']);
            $article = C::t('portal_article_title')->fetch($aid);
            
            $article_count = C::t('portal_article_count')->fetch($aid);
            if($article_count) $article = array_merge($article_count, $article);
            $article['related'] = array();
            if(($relateds = C::t('portal_article_related')->fetch_all_by_aid($aid))) {
                    foreach(C::t('portal_article_title')->fetch_all(array_keys($relateds)) as $raid => $value) {
                            $value['uri'] = fetch_article_url($value);
                            if($value['pic']) {
                                $value['pic'] = $_G['setting']['siteurl'].pic_get($value['pic'], '', $value['thumb'], $value['remote'], 1, 1);
                            }
                            $article['related'][] = $value;
                    }
            }
            if($article['pic']) {
                $article['pic'] = $_G['setting']['siteurl'].pic_get($article['pic'], '', $article['thumb'], $article['remote'], 1, 1);
            }
            return $this->formatArticle($article,$content);
        }
        function formatArticle($article,$content){
            global $_G;
            $res['catid']      = $article['catid'];
            $res['cid']        = $content['cid'];
            $res['aid']        = $content['aid'];
            $res['title']      = htmlspecialchars_decode($article['title']);
            $res['author']     = htmlspecialchars_decode($article['author']);
            $res['username']   = htmlspecialchars_decode($article['username']);
            $res['uid']        = $article['uid'];
            $res['avatar']     = avatar($article['uid'],'middle',1);
            $res['dateline']   = $content['dateline'];
            $res['viewnum']    = $article['viewnum'];
            $res['commentnum'] = $article['commentnum'];
            $res['sharetimes'] = $article['sharetimes'];
            $res['favtimes']   = $article['favtimes'];
            $res['summary']    = $article['summary'];
            $res['content']    = htmlspecialchars_decode(message_filter($content['content'],1));
            $res['related']   =  $article['related'];
            $res['pic']        = $article['pic'];
            $res['allowcomment']= $article['allowcomment'];
            $res['status']     = $article['status'];
            $res['click1']     = $article['click1'];
            $res['click2']     = $article['click2'];
            $res['click3']     = $article['click3'];
            $res['click4']     = $article['click4'];
            $res['click5']     = $article['click5'];
            $res['shareurl']   = get_site_url().'plugin.php?id=bbssdk:portal&aid='.$content['aid'];
            $res['attachment'] = $attachs = array();
            $res['content'] = preg_replace("%\[attach.*attach\]%isU", '', $res['content']);
            foreach(C::t('portal_attachment')->fetch_all_by_aid($res['aid']) as $value) {
                if(!$value['isimage']) {
                    $value['url'] = $value['remote'] ? $_G['setting']['ftp']['attachurl'].'portal/'.$value['attachment'] : rtrim($_G['setting']['siteurl'],'/').'/data/attachment/portal/'.$value['attachment'];
                    $attachs[] = $value;
                    
                } 
            }
            $res['attachment'] = $attachs;
            return $res;
        }
        function get_categories(){
            global $_G;
            loadcache('portalcategory');
            $category = $_G['cache']['portalcategory'];
            $list = array();
            if($category){
                foreach ($category as $v){
                    $list[] = $this->formatCategory($v);
                }
            }
            $this->success_result($list);
        }
        function get_categoryitem(){
            global $_G;
            $catid = intval($_REQUEST['catid']);
            $cat = array();
            loadcache('portalcategory');
            $category = $_G['cache']['portalcategory'];
            if($category){
                foreach ($category as $v){
                    if($v['catid'] == $catid){
                        $cat = $this->formatCategory($v);
                        break;
                    }
                }
            }
            $this->success_result($cat);
        }
        function formatCategory($item){
            $res['catid']          = $item['catid'];
            $res['upid']           = $item['upid'];
            $res['catname']        = htmlspecialchars_decode($item['catname']);
            $res['articles']       = $item['articles'];
            $res['allowcomment']   = $item['allowcomment'];
            $res['displayorder']   = $item['displayorder'];
            $res['disallowpublish']= $item['disallowpublish'];
            $res['closed']         = $item['closed'];
            return $res;
        }
}