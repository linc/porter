<?php
/**
 * vBulletin exporter tool.
 *
 * This will migrate all vBulletin data for 3.x and 4.x forums.
 * It migrates all attachments from 2.x and later.
 *
 * Supports the FileUpload, ProfileExtender, and Signature plugins.
 * All vBulletin data appropriate for those plugins will be prepared
 * and transferred.
 *
 * To export only 1 category, add 'forumid=#' parameter to the URL.
 * To extract avatars stored in database, add 'avatars=1' parameter to the URL.
 * To extract attachments stored in db, add 'attachments=1' parameter to the URL.
 * To extract all usermeta data (title, skype, custom profile fields, etc),
 *    add 'usermeta=1' parameter to the URL.
 * To stop the export after only extracting files, add 'noexport=1' param to the URL.
 *
 * TO MIGRATE FILES, BEFORE IMPORTING YOU MUST:
 * 1) Copy entire 'customavatars' folder into Vanilla's /upload folder.
 * 2) Copy entire 'attachments' folder into Vanilla's / upload folder.
 * 3) Make BOTH folders writable by the server.
 * 4) Enable the FileUpload plugin. (Media table must be present.)
 *
 * filepath - Command line option to fix / check files are on disk.  Files named .attach are renamed
 * to the proper name and missing files are reported in missing-files.txt.
 *
 * @copyright Vanilla Forums Inc. 2010
 * @author Matt Lincoln Russell lincoln@icrontic.com
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */

$supported['vbulletin'] = array('name' => 'vBulletin 3 & 4', 'prefix' => 'vb_');
// Commented commands are still supported, if you really want to use them.
$supported['vbulletin']['CommandLine'] = array(
    //'noexport' => array('Exports only the blobs.', 'Sx' => '::'),
    'mindate' => array('A date to import from. Like selective amnesia.'),
    //'forumid' => array('Only export 1 forum'),
    //'ipbanlist' => array('Export IP ban list, which is a terrible idea.'),
    'filepath' => array('Full path of file attachments to be renamed.', 'Sx' => '::')
);
$supported['vbulletin']['features'] = array(
    'Comments' => 1,
    'Discussions' => 1,
    'Users' => 1,
    'Categories' => 1,
    'Roles' => 1,
    'Avatars' => 1,
    'Attachments' => 1,
    'PrivateMessages' => 1,
    'Permissions' => 1,
    'UserWall' => 1,
    'UserNotes' => 1,
    'Bookmarks' => 1,
    'Passwords' => 1,
    'Signatures' => 1,
    'Ranks' => 1,
    'Polls' => 1,
);

/**
 * vBulletin-specific extension of generic ExportController.
 *
 * @package VanillaPorter
 */
class VBulletin extends ExportController {
    /* @var string SQL fragment to build new path to attachments. */
    public $attachSelect = "concat('/vbulletin/', left(f.filehash, 2), '/', f.filehash, '_', a.attachmentid,'.', f.extension) as Path";

    /* @var string SQL fragment to build new path to user photo. */
    public $avatarSelect = "case
      when a.userid is not null then concat('customavatars/', a.userid % 100,'/avatar_', a.userid, right(a.filename, instr(reverse(a.filename), '.')))
      when av.avatarpath is not null then av.avatarpath
      else null
      end as customphoto";

    /* @var array Default permissions to map. */
    public static $permissions = array(

        'genericpermissions' => array(
            1 => array('Garden.Profiles.View', 'Garden.Activity.View'),
            2 => 'Garden.Profiles.Edit',
            1024 => 'Plugins.Signatures.Edit'
        ),
        'forumpermissions' => array(
            1 => 'Vanilla.Discussions.View',
            16 => 'Vanilla.Discussions.Add',
            64 => 'Vanilla.Comments.Add',
            4096 => 'Plugins.Attachments.Download',
            8192 => 'Plugins.Attachments.Upload'
        ),
        'adminpermissions' => array(
            1 => array(
                'Garden.Moderation.Manage',
                'Vanilla.Discussions.Announce',
                'Vanilla.Discussions.Close',
                'Vanilla.Discussions.Delete',
                'Vanilla.Comments.Delete',
                'Vanilla.Comments.Edit',
                'Vanilla.Discussions.Edit',
                'Vanilla.Discussions.Sink',
                'Garden.Activity.Delete',
                'Garden.Users.Add',
                'Garden.Users.Edit',
                'Garden.Users.Approve',
                'Garden.Users.Delete',
                'Garden.Applicants.Manage'
            ),
            2 => array(
                'Garden.Settings.View',
                'Garden.Settings.Manage',
                'Garden.Messages.Manage',
                'Vanilla.Spam.Manage'
            )
//          4 => 'Garden.Settings.Manage',),
        ),
//      'wolpermissions' => array(
//          16 => 'Plugins.WhosOnline.ViewHidden')
    );

    public static $permissions2 = array();

    /** @var array Required tables => columns. Commented values are optional. */
    protected $sourceTables = array(
        //'attachment'
        //'contenttype'
        //'customavatar'
        'deletionlog' => array('type', 'primaryid'),
        //'filedata'
        'forum' => array('forumid', 'description', 'displayorder', 'title', 'description', 'displayorder'),
        //'phrase' => array('varname','text','product','fieldname','varname'),
        //'pm'
        //'pmgroup'
        //'pmreceipt'
        //'pmtext'
        'post' => array('postid', 'threadid', 'pagetext', 'userid', 'dateline', 'visible'),
        //'setting'
        'subscribethread' => array('userid', 'threadid'),
        'thread' => array(
            'threadid',
            'forumid',
            'postuserid',
            'title',
            'open',
            'sticky',
            'dateline',
            'lastpost',
            'visible'
        ),
        //'threadread'
        'user' => array(
            'userid',
            'username',
            'password',
            'email',
            'referrerid',
            'timezoneoffset',
            'posts',
            'salt',
            'birthday_search',
            'joindate',
            'lastvisit',
            'lastactivity',
            'membergroupids',
            'usergroupid',
            'usertitle',
            'homepage',
            'aim',
            'icq',
            'yahoo',
            'msn',
            'skype',
            'styleid',
            'avatarid'
        ),
        //'userban'
        'userfield' => array('userid'),
        'usergroup' => array('usergroupid', 'title', 'description'),
        //'visitormessage'
    );

    /**
     * Export each table one at a time.
     *
     * @param ExportModel $Ex
     */
    protected function forumExport($ex) {
        // Allow limited export of 1 category via ?forumid=ID
        $forumID = $this->param('forumid');
        if ($forumID) {
            $forumWhere = ' and t.forumid ' . (strpos($forumID, ', ') === false ? "= $forumID" : "in ($forumID)");
        } else {
            $forumWhere = '';
        }

        $characterSet = $ex->getCharacterSet('post');
        if ($characterSet) {
            $ex->characterSet = $characterSet;
        }

        // Begin
        $ex->beginExport('', 'vBulletin 3.* and 4.*');
        $this->exportBlobs(
            $this->param('files'),
            $this->param('avatars'),
            $forumWhere
        );

        if ($this->param('noexport')) {
            $ex->comment('Skipping the export.');
            $ex->endExport();

            return;
        }
        // Check to see if there is a max date.
        $minDate = $this->param('mindate');
        if ($minDate) {
            $minDate = strtotime($minDate);
            $ex->comment("Min topic date ($minDate): " . date('c', $minDate));
        }
        $now = time();

        $cdn = $this->param('cdn', '');

        // Grab all of the ranks.
        $ranks = $ex->get("select * from :_usertitle order by minposts desc", 'usertitleid');

        // Users
        $user_Map = array(
            'userid' => 'UserID',
            'username' => 'Name',
            'password2' => 'Password',
            'email' => 'Email',
            'referrerid' => 'InviteUserID',
            'timezoneoffset' => 'HourOffset',
            'ipaddress' => 'LastIPAddress',
            'ipaddress2' => 'InsertIPAddress',
            'usertitle' => array(
                'Column' => 'Title',
                'Filter' => function ($value) {
                    return trim(strip_tags(str_replace('&nbsp;', ' ', $value)));
                }
            ),
            'posts' => array(
                'Column' => 'RankID',
                'Filter' => function ($value) use ($ranks) {
                    // Look  up the posts in the ranks table.
                    foreach ($ranks as $rankID => $row) {
                        if ($value >= $row['minposts']) {
                            return $rankID;
                        }
                    }

                    return null;
                }
            )
        );

        // Use file avatar or the result of our blob export?
        if ($this->getConfig('usefileavatar')) {
            $user_Map['filephoto'] = 'Photo';
        } else {
            $user_Map['customphoto'] = 'Photo';
        }

        $ex->exportTable('User', "select u.*,
            ipaddress as ipaddress2,
            concat(`password`, salt) as password2,
            DATE_FORMAT(birthday_search,GET_FORMAT(DATE,'ISO')) as DateOfBirth,
            FROM_UNIXTIME(joindate) as DateFirstVisit,
            FROM_UNIXTIME(lastvisit) as DateLastActive,
            FROM_UNIXTIME(joindate) as DateInserted,
            FROM_UNIXTIME(lastactivity) as DateUpdated,
            case when avatarrevision > 0 then concat('$cdn', 'userpics/avatar', u.userid, '_', avatarrevision, '.gif')
                 when av.avatarpath is not null then av.avatarpath
                 else null
                 end as filephoto,
            {$this->avatarSelect},
            case when ub.userid is not null then 1 else 0 end as Banned,
            'vbulletin' as HashMethod
         from :_user u
         left join :_customavatar a
            on u.userid = a.userid
         left join :_avatar av
            on u.avatarid = av.avatarid
         left join :_userban ub
              on u.userid = ub.userid and ub.liftdate <= now() ",
            $user_Map);  // ":_" will be replace by database prefix

        // Roles
        $role_Map = array(
            'usergroupid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description'
        );
        $ex->exportTable('Role', 'select * from :_usergroup', $role_Map);

        // UserRoles
        $userRole_Map = array(
            'userid' => 'UserID',
            'usergroupid' => 'RoleID'
        );
        $ex->query("CREATE TEMPORARY TABLE VbulletinRoles (userid INT UNSIGNED NOT NULL, usergroupid INT UNSIGNED NOT NULL)");
        # Put primary groups into tmp table
        $ex->query("insert into VbulletinRoles (userid, usergroupid) select userid, usergroupid from :_user");
        # Put stupid CSV column into tmp table
        $secondaryRoles = $ex->query("select userid, usergroupid, membergroupids from :_user", true);
        if (is_resource($secondaryRoles)) {
            while (($row = @mysql_fetch_assoc($secondaryRoles)) !== false) {
                if ($row['membergroupids'] != '') {
                    $groups = explode(',', $row['membergroupids']);
                    foreach ($groups as $groupID) {
                        if (!$groupID) {
                            continue;
                        }
                        $ex->query("insert into VbulletinRoles (userid, usergroupid) values({$row['userid']},{$groupID})",
                            true);
                    }
                }
            }
        }
        # Export from our tmp table and drop
        $ex->exportTable('UserRole', 'select distinct userid, usergroupid from VbulletinRoles', $userRole_Map);
        $ex->query("DROP TABLE IF EXISTS VbulletinRoles");

        // Permissions.
        $permissions_Map = array(
            'usergroupid' => 'RoleID',
            'title' => array('Column' => 'Garden.SignIn.Allow', 'Filter' => array($this, 'signInPermission')),
            'genericpermissions' => array('Column' => 'GenericPermissions', 'type' => 'int'),
            'forumpermissions' => array('Column' => 'ForumPermissions', 'type' => 'int')
        );
        $this->addPermissionColumns(self::$permissions, $permissions_Map);
        $ex->exportTable('Permission', 'select * from :_usergroup', $permissions_Map);

//      $Ex->EndExport();
//      return;

        // UserMeta
        $ex->query("CREATE TEMPORARY TABLE VbulletinUserMeta (`UserID` INT NOT NULL ,`Name` VARCHAR( 255 ) NOT NULL ,`Value` text NOT NULL)");
        # Standard vB user data
        $userFields = array(
            'usertitle' => 'Title',
            'homepage' => 'Website',
            'skype' => 'Skype',
            'styleid' => 'StyleID'
        );
        foreach ($userFields as $field => $insertAs) {
            $ex->query("insert into VbulletinUserMeta (UserID, Name, Value) select userid, 'Profile.$insertAs', $field from :_user where $field != ''");
        }
        # Dynamic vB user data (userfield)
        $profileFields = $ex->query("select varname, text from :_phrase where product='vbulletin' and fieldname='cprofilefield' and varname like 'field%_title'");
        if (is_resource($profileFields)) {
            $profileQueries = array();
            while ($field = @mysql_fetch_assoc($profileFields)) {
                $column = str_replace('_title', '', $field['varname']);
                $name = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $field['text']);
                $profileQueries[] = "insert into VbulletinUserMeta (UserID, Name, Value)
               select userid, 'Profile." . $name . "', " . $column . " from :_userfield where " . $column . " != ''";
            }
            foreach ($profileQueries as $query) {
                $ex->query($query);
            }
        }


        // Signatures
        $sql = "
         select
            userid as UserID,
            'Plugin.Signatures.Sig' as Name,
            signature as Value
         from :_usertextfield
         where nullif(signature, '') is not null

         union

         select
            userid,
            'Plugin.Signatures.Format',
            'BBCode'
         from :_usertextfield
         where nullif(signature, '') is not null";
        $ex->exportTable('UserMeta', $sql);


        // Ranks
        $rank_Map = array(
            'usertitleid' => 'RankID',
            'title' => 'Name',
            'title2' => 'Label',
            'minposts' => array(
                'Column' => 'Attributes',
                'Filter' => function ($value) {
                    $result = array(
                        'Criteria' => array(
                            'CountPosts' => $value
                        )
                    );

                    return serialize($result);
                }
            ),
            'level' => array(
                'Column' => 'Level',
                'Filter' => function ($value) {
                    static $level = 1;

                    return $level++;
                }
            )
        );
        $ex->exportTable('Rank', "
         select ut.*, ut.title as title2, 0 as level
         from :_usertitle ut
         order by ut.minposts", $rank_Map);


        // Categories
        $category_Map = array(
            'forumid' => 'CategoryID',
            'description' => 'Description',
            'Name2' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'displayorder' => array('Column' => 'Sort', 'Type' => 'int'),
            'parentid' => 'ParentCategoryID'
        );
        $ex->exportTable('Category', "select f.*, title as Name2
         from :_forum f
         where 1 = 1 $forumWhere", $category_Map);

        $minDiscussionID = false;
        $minDiscussionWhere = false;
        if ($minDate) {
            $minDiscussionID = $ex->getValue("
            select max(threadid)
            from :_thread
            where dateline < $minDate
            ", false);

            $minDiscussionID2 = $ex->getValue("
            select min(threadid)
            from :_thread
            where dateline >= $minDate
            ", false);

            // The two discussion IDs should be the same, but let's average them.
            $minDiscussionID = floor(($minDiscussionID + $minDiscussionID2) / 2);

            $ex->comment('Min topic id: ' . $minDiscussionID);
        }

        // Discussions
        $discussion_Map = array(
            'threadid' => 'DiscussionID',
            'forumid' => 'CategoryID',
            'postuserid' => 'InsertUserID',
            'postuserid2' => 'UpdateUserID',
            'title' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'Format' => 'Format',
            'views' => 'CountViews',
            'ipaddress' => 'InsertIPAddress'
        );

        if ($ex->destination == 'database') {
            // Remove the filter from the title so that this doesn't take too long.
            $ex->HTMLDecoderDb('thread', 'title', 'threadid');
            unset($discussion_Map['title']['Filter']);
        }

        if ($minDiscussionID) {
            $minDiscussionWhere = "and t.threadid > $minDiscussionID";
        }

        $ex->exportTable('Discussion', "select t.*,
            t.postuserid as postuserid2,
            p.ipaddress,
            p.pagetext as Body,
            'BBCode' as Format,
            replycount+1 as CountComments,
            convert(ABS(open-1),char(1)) as Closed,
            if(convert(sticky,char(1))>0,2,0) as Announce,
            FROM_UNIXTIME(t.dateline) as DateInserted,
            FROM_UNIXTIME(lastpost) as DateUpdated,
            FROM_UNIXTIME(lastpost) as DateLastComment
         from :_thread t
            left join :_deletionlog d on (d.type='thread' and d.primaryid=t.threadid)
            left join :_post p on p.postid = t.firstpostid
         where d.primaryid is null
            and t.visible = 1
            $minDiscussionWhere
            $forumWhere", $discussion_Map);

        // Comments
        $comment_Map = array(
            'postid' => 'CommentID',
            'threadid' => 'DiscussionID',
            'pagetext' => 'Body',
            'Format' => 'Format',
            'ipaddress' => 'InsertIPAddress'
        );

        if ($minDiscussionID) {
            $minDiscussionWhere = "and p.threadid > $minDiscussionID";
        }

        $ex->exportTable('Comment', "select p.*,
            'BBCode' as Format,
            p.userid as InsertUserID,
            p.userid as UpdateUserID,
         FROM_UNIXTIME(p.dateline) as DateInserted,
            FROM_UNIXTIME(p.dateline) as DateUpdated
         from :_post p
         inner join :_thread t
            on p.threadid = t.threadid
         left join :_deletionlog d
            on (d.type='post' and d.primaryid=p.postid)
         where p.postid <> t.firstpostid
            and d.primaryid is null
            and p.visible = 1
            $minDiscussionWhere
            $forumWhere", $comment_Map);

        // UserDiscussion
        if ($minDiscussionID) {
            $minDiscussionWhere = "where st.threadid > $minDiscussionID";
        }

        $ex->exportTable('UserDiscussion', "select
            st.userid as UserID,
            st.threadid as DiscussionID,
            '1' as Bookmarked,
            FROM_UNIXTIME(tr.readtime) as DateLastViewed
         from :_subscribethread st
         left join :_threadread tr on tr.userid = st.userid and tr.threadid = st.threadid
         $minDiscussionWhere");
        /*$ex->exportTable('UserDiscussion', "select
             tr.userid as UserID,
             tr.threadid as DiscussionID,
             FROM_UNIXTIME(tr.readtime) as DateLastViewed,
             case when st.threadid is not null then 1 else 0 end as Bookmarked
           from :_threadread tr
           left join :_subscribethread st on tr.userid = st.userid and tr.threadid = st.threadid");*/

        // Activity (from visitor messages in vBulletin 3.8+)
        if ($ex->exists('visitormessage')) {
            if ($minDiscussionID) {
                $minDiscussionWhere = "and dateline > $minDiscussionID";
            }


            $activity_Map = array(
                'postuserid' => 'RegardingUserID',
                'userid' => 'ActivityUserID',
                'pagetext' => 'Story',
                'NotifyUserID' => 'NotifyUserID',
                'Format' => 'Format'
            );
            $ex->exportTable('Activity', "select *,
               '{RegardingUserID,you} &rarr; {ActivityUserID,you}' as HeadlineFormat,
               FROM_UNIXTIME(dateline) as DateInserted,
               FROM_UNIXTIME(dateline) as DateUpdated,
               INET_NTOA(ipaddress) as InsertIPAddress,
               postuserid as InsertUserID,
               -1 as NotifyUserID,
               'BBCode' as Format,
               'WallPost' as ActivityType
            from :_visitormessage
            where state='visible'
               $minDiscussionWhere", $activity_Map);
        }

        $this->_exportConversations($minDate);

        $this->_exportPolls();

        // Media
        if ($ex->exists('attachment')) {
            $this->exportMedia($minDiscussionID);
        }

        // IP Ban list
        $ipBanlist = $this->param('ipbanlist');
        if ($ipBanlist) {

            $ex->query("DROP TABLE IF EXISTS `z_ipbanlist` ");
            $ex->query("CREATE TABLE `z_ipbanlist` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `ipaddress` varchar(50) DEFAULT NULL,
           PRIMARY KEY (`id`),
           UNIQUE KEY `ipaddress` (`ipaddress`)

         ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            $result = $ex->query("select value from :_setting where varname = 'banip'");
            $row = mysql_fetch_assoc($result);

            if ($row) {
                $insertSql = 'INSERT IGNORE INTO `z_ipbanlist` (`ipaddress`) values ';
                $ipString = str_replace("\r", "", $row['value']);
                $IPs = explode("\n", $ipString);
                foreach ($IPs as $IP) {
                    $IP = trim($IP);
                    if (empty($IP)) {
                        continue;
                    }
                    $insertSql .= '(\'' . mysql_real_escape_string($IP) . '\'), ';
                }
                $insertSql = substr($insertSql, 0, -2);
                $ex->query($insertSql);

                $ban_Map = array();
                $ex->exportTable('Ban',
                    "select 'IPAddress' as BanType, ipaddress as BanValue, 'Imported ban' as Notes, NOW() as DateInserted
                  FROM `z_ipbanlist`",
                    $ban_Map);

                $ex->query('DROP table if exists `z_ipbanlist` ');

            }
        }


        // End
        $ex->endExport();
    }

    protected function _exportConversations($minDate) {
        $ex = $this->ex;

        if ($minDate) {
            $minID = $ex->getValue("
            select max(pmtextid)
            from :_pmtext
            where dateline < $minDate
            ", false);
        } else {
            $minID = false;
        }
        $minWhere = '';

        $ex->query('drop table if exists z_pmto');
        $ex->query('create table z_pmto (
        pmtextid int unsigned,
        userid int unsigned,
        primary key(pmtextid, userid)
      )');

        if ($minID) {
            $minWhere = "where pmtextid > $minID";
        }

        $ex->query("insert ignore z_pmto (
        pmtextid,
        userid
      )
      select
        pmtextid,
        userid
      from :_pm
      $minWhere");

        $ex->query("insert ignore z_pmto (
        pmtextid,
        userid
      )
      select
        pmtextid,
        fromuserid
      from :_pmtext
      $minWhere;");

        $ex->query("insert ignore z_pmto (
        pmtextid,
        userid
      )
      select
        pm.pmtextid,
        r.userid
      from :_pm pm
      join :_pmreceipt r
        on pm.pmid = r.pmid
      $minWhere;");

        $ex->query("insert ignore z_pmto (
        pmtextid,
        userid
      )
      select
        pm.pmtextid,
        r.touserid
      from :_pm pm
      join :_pmreceipt r
        on pm.pmid = r.pmid
      $minWhere;");

        $ex->query('drop table if exists z_pmto2;');
        $ex->query('create table z_pmto2 (
        pmtextid int unsigned,
        userids varchar(250),
        primary key (pmtextid)
      );');

        $ex->query('insert z_pmto2 (
        pmtextid,
        userids
      )
      select
        pmtextid,
        group_concat(userid order by userid)
      from z_pmto t
      group by t.pmtextid;');

        $ex->query('drop table if exists z_pmtext;');
        $ex->query('create table z_pmtext (
        pmtextid int unsigned,
        title varchar(250),
        title2 varchar(250),
        userids varchar(250),
        group_id int unsigned
      );');

        $ex->query("insert z_pmtext (
        pmtextid,
        title,
        title2
      )
      select
        pmtextid,
        title,
        case when title like 'Re: %' then trim(substring(title, 4)) else title end as title2
      from :_pmtext pm
      $minWhere;");
        $ex->query('create index z_idx_pmtext on z_pmtext (pmtextid);');

        $ex->query('update z_pmtext pm
      join z_pmto2 t
        on pm.pmtextid = t.pmtextid
      set pm.userids = t.userids;');

        // A conversation is a group of pmtexts with the same title and same users.

        $ex->query('drop table if exists z_pmgroup;');
        $ex->query('create table z_pmgroup (
        group_id int unsigned,
        title varchar(250),
        userids varchar(250)
      );');

        $ex->query("insert z_pmgroup (
        group_id,
        title,
        userids
      )
      select
        min(pm.pmtextid),
        pm.title2,
        t2.userids
      from z_pmtext pm
      join z_pmto2 t2
        on pm.pmtextid = t2.pmtextid
      group by pm.title2, t2.userids;");

        $ex->query('create index z_idx_pmgroup on z_pmgroup (title, userids);');
        $ex->query('create index z_idx_pmgroup2 on z_pmgroup (group_id);');

        $ex->query('update z_pmtext pm
      join z_pmgroup g
        on pm.title2 = g.title and pm.userids = g.userids
      set pm.group_id = g.group_id;');

        // Conversations.
        $conversation_Map = array(
            'pmtextid' => 'ConversationID',
            'fromuserid' => 'InsertUserID',
            'title2' => array('Column' => 'Subject', 'Type' => 'varchar(250)')
        );
        $ex->exportTable('Conversation',
            'select
         pm.*,
         g.title as title2,
         FROM_UNIXTIME(pm.dateline) as DateInserted
       from :_pmtext pm
       join z_pmgroup g
         on g.group_id = pm.pmtextid', $conversation_Map);

        // Coversation Messages.
        $conversationMessage_Map = array(
            'pmtextid' => 'MessageID',
            'group_id' => 'ConversationID',
            'message' => 'Body',
            'fromuserid' => 'InsertUserID'
        );
        $ex->exportTable('ConversationMessage',
            "select
         pm.*,
         pm2.group_id,
         'BBCode' as Format,
         FROM_UNIXTIME(pm.dateline) as DateInserted
       from :_pmtext pm
       join z_pmtext pm2
         on pm.pmtextid = pm2.pmtextid", $conversationMessage_Map);

        // User Conversation.
        $userConversation_Map = array(
            'userid' => 'UserID',
            'group_id' => 'ConversationID'
        );
        $ex->exportTable('UserConversation',
            "select
         g.group_id,
         t.userid
       from z_pmto t
       join z_pmgroup g
         on g.group_id = t.pmtextid;", $userConversation_Map);

        $ex->query('drop table if exists z_pmto');
        $ex->query('drop table if exists z_pmto2;');
        $ex->query('drop table if exists z_pmtext;');
        $ex->query('drop table if exists z_pmgroup;');
    }

    /**
     * Converts database blobs into files.
     *
     * Creates /attachments and /customavatars folders in the same directory as the export file.
     *
     * @param bool $Attachments Whether to move attachments.
     * @param bool $CustomAvatars Whether to move avatars.
     */
    public function exportBlobs($attachments = true, $customAvatars = true) {
        $ex = $this->ex;
        if ($ex->exists('attachment', array('contenttypeid', 'contentid')) === true) {
            $extension = ExportModel::fileExtension('a.filename');
        } else {
            $extension = ExportModel::fileExtension('filename');
        }

        if ($attachments) {
            $identity = 'f.attachmentid';
            if ($ex->exists('attachment', array('contenttypeid', 'contentid')) === true
                || $ex->exists('attach') === true) {
                $identity = 'f.filedataid';
            }

            $sql = "select
               f.filedata,
               $extension as extension,
               concat('attachments/', f.userid, '/', $identity, '.', lower(extension)) as Path
               from ";

            // Table is dependent on vBulletin version (v4+ is filedata, v3 is attachment)
            if ($ex->exists('attachment', array('contenttypeid', 'contentid')) === true) {
                $sql .= ":_filedata f left join :_attachment a on a.filedataid = f.filedataid";
            } elseif ($ex->exists('attach') === true) {
                $sql .= ":_filedata f left join :_attach a on a.filedataid = f.filedataid";
            } else {
                $sql .= ":_attachment f";
            }

            $ex->exportBlobs($sql, 'filedata', 'Path');
        }

        if ($customAvatars) {
            $sql = "select
               a.filedata,
               case when a.userid is not null then concat('customavatars/', a.userid % 100,'/avatar_', a.userid, right(a.filename, instr(reverse(a.filename), '.')))
                  else null end as customphoto
            from :_customavatar a
            ";
            $sql = str_replace('u.userid', 'a.userid', $sql);
            $ex->exportBlobs($sql, 'filedata', 'customphoto', 80);
        }

        // Export the group icons no matter what.
        if ($ex->exists('socialgroupicon', 'thumbnail_filedata') && ($attachments || $customAvatars)) {
            $sql = "
            select
               i.filedata,
               concat('vb/groupicons/', i.groupid, '.', i.extension) as path
            from :_socialgroupicon i";
            $ex->exportBlobs($sql, 'filedata', 'path');
        }
    }

    /**
     * Export the attachments as Media.
     *
     * In vBulletin 4.x, the filedata table was introduced.
     */
    public function exportMedia($minDiscussionID = false) {
        $ex = $this->ex;
        $instance = $this;

        if ($minDiscussionID) {
            $discussionWhere = "and t.threadid > $minDiscussionID";
        } else {
            $discussionWhere = '';
        }
        $media_Map = array(
            'attachmentid' => 'MediaID',
            'filename' => 'Name',
            'filesize' => 'Size',
            'userid' => 'InsertUserID',
            'extension' => array('Column' => 'Type', 'Filter' => array($this, 'BuildMimeType')),
            'filehash' => array('Column' => 'Path', 'Filter' => array($this, 'BuildMediaPath')),
            'filethumb' => array(
                'Column' => 'ThumbPath',
                'Filter' => function($value, $field, $row) use ($instance) {
                    $filteredData = $this->filterThumbnailData($value, $field, $row);

                    if ($filteredData) {
                        return $instance->buildMediaPath($value, $field, $row);
                    } else {
                        return null;
                    }
                }
            ),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            'height' => array('Column' => 'ImageHeight', 'Filter' => array($this, 'buildMediaDimension')),
            'width' => array('Column' => 'ImageWidth', 'Filter' => array($this, 'buildMediaDimension')),
        );

        // Add hash fields if they exist (from 2.x)
        $attachColumns = array('hash', 'filehash');
        $missing = $ex->exists('attachment', $attachColumns);
        $attachColumnsString = '';
        foreach ($attachColumns as $columnName) {
            if (in_array($columnName, $missing)) {
                $attachColumnsString .= ", null as $columnName";
            } else {
                $attachColumnsString .= ", a.$columnName";
            }
        }
        // Do the export
        if ($ex->exists('attachment', array('contenttypeid', 'contentid')) === true) {
            // Exporting 4.x with 'filedata' table.
            // Build an index to join on.
            $result = $ex->query('show index from :_thread where Key_name = "ix_thread_firstpostid"');
            if (!$result) {
                $ex->query('create index ix_thread_firstpostid on :_thread (firstpostid)');
            }
            $mediaSql = "
                select
                    case
                        when t.threadid is not null then 'discussion'
                        when ct.class = 'Post' then 'comment'
                        when ct.class = 'Thread' then 'discussion'
                        else ct.class
                    end as ForeignTable,
                    case
                        when t.threadid is not null then t.threadid
                        else a.contentid
                    end as ForeignID,
                    FROM_UNIXTIME(a.dateline) as DateInserted,
                    a.*,
                    f.extension,
                    f.filesize/*,*/
                    $attachColumnsString,
                    f.width,
                    f.height,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_attachment a
                    join :_contenttype ct on a.contenttypeid = ct.contenttypeid
                    join :_filedata f on f.filedataid = a.filedataid
                    left join :_thread t on t.firstpostid = a.contentid and a.contenttypeid = 1
                where a.contentid > 0
                    $discussionWhere
            ";
            $ex->exportTable('Media', $mediaSql, $media_Map);

        } else {
            // Exporting 3.x without 'filedata' table.
            // Do NOT grab every field to avoid 'filedata' blob in 3.x.
            // Left join 'attachment' because we can't left join 'thread' on firstpostid (not an index).
            // Lie about the height & width to spoof FileUpload serving generic thumbnail if they aren't set.
            $extension = ExportModel::fileExtension('a.filename');
            $mediaSql = "
                select
                    a.attachmentid,
                    a.filename,
                    $extension as extension/*,*/
                    $attachColumnsString,
                    a.userid,
                    'discussion' as ForeignTable,
                    t.threadid as ForeignID,
                    FROM_UNIXTIME(a.dateline) as DateInserted,
                    '1' as height,
                    '1' as width,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_thread t
                    left join :_attachment a ON a.postid = t.firstpostid
                where a.attachmentid > 0

                union all

                select
                    a.attachmentid,
                    a.filename,
                    $extension as extension/*,*/
                    $attachColumnsString,
                    a.userid,
                    'comment' as ForeignTable,
                    a.postid as ForeignID,
                    FROM_UNIXTIME(a.dateline) as DateInserted,
                    '1' as height,
                    '1' as width,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_post p
                    inner join :_thread t ON p.threadid = t.threadid
                    left join :_attachment a ON a.postid = p.postid
                where p.postid <> t.firstpostid and a.attachmentid > 0
            ";
            $ex->exportTable('Media', $mediaSql, $media_Map);
        }

        // files named .attach need to be named properly.
        // file needs to be renamed and db updated.
        // if its an images; we need to include .thumb
        $attachmentPath = $this->param('filepath');
        if ($attachmentPath) {
            $missingFiles = array();
            if (is_dir($attachmentPath)) {
                $ex->comment("Checking files");
                $result = $ex->query($mediaSql);
                while ($row = mysql_fetch_assoc($result)) {
                    $filePath = $this->buildMediaPath('', '', $row);
                    $cdn = $this->param('cdn', '');

                    if (!empty($cdn)) {
                        $filePath = str_replace($cdn, '', $filePath);
                    }
                    $fullPath = $attachmentPath . $filePath;
                    if (file_exists($fullPath)) {
                        continue;
                    }

                    //check if named .attach
                    $p = explode('.', $fullPath);
                    $attachFilename = str_replace(end($p), 'attach', $fullPath);
                    if (file_exists($attachFilename)) {
                        // rename file
                        rename($attachFilename, $fullPath);
                        continue;
                    }

                    //check if md5 hash in root
                    if (getValue('hash', $row)) {
                        $md5Filename = $attachmentPath . $row['hash'] . '.' . $row['extension'];
                        if (file_exists($md5Filename)) {
                            // rename file
                            rename($md5Filename, $fullPath);
                            continue;
                        }
                    }

                    $missingFiles[] = $filePath;

                }
            } else {
                $ex->comment('Attachment Path not found');
            }
            $totalMissingFiles = count($missingFiles);
            if ($totalMissingFiles > 0) {
                $ex->comment('Missing files detected.  See ./missing_files.txt for full list.');
                $ex->comment(sprintf('Total missing files %d', $totalMissingFiles));
                file_put_contents('missing-files.txt', implode("\n", $missingFiles));
            }

        }
    }

    protected function _exportPolls() {
        $ex = $this->ex;
        $fp = $ex->file;
//      $fp = fopen('php://output', 'ab');

        $poll_Map = array(
            'pollid' => 'PollID',
            'question' => 'Name',
            'threadid' => 'DiscussionID',
            'anonymous' => 'Anonymous',
            'dateline' => array('Column' => 'DateInserted', 'Filter' => 'TimestampToDate'),
            'postuserid' => 'InsertUserID'
        );
        $ex->exportTable('Poll',
            "select
            p.*,
            t.threadid,
            t.postuserid,
            !p.public as anonymous
         from :_poll p
         join :_thread t
            on p.pollid = t.pollid", $poll_Map);

        $pollOption_Map = array(
            'optionid' => 'PollOptionID', // calc
            'pollid' => 'PollID',
            'body' => 'Body', // calc
            'sort' => 'Sort', // calc
            'dateline' => array('Column' => 'DateInserted', 'Filter' => 'TimestampToDate'),
            'postuserid' => 'InsertUserID'
        );
        $sql = "select
         p.*,
         'BBCode' as Format,
         t.postuserid
      from :_poll p
      join :_thread t
         on p.pollid = t.pollid";

        // Some custom programming needs to be done here so let's do that.
        $exportStructure = $ex->getExportStructure($pollOption_Map, 'PollOption', $pollOption_Map);
        $revMappings = $ex->flipMappings($pollOption_Map);

        $ex->writeBeginTable($fp, 'PollOption', $exportStructure);

        $r = $ex->query($sql);
        $rowCount = 0;
        while ($row = mysql_fetch_assoc($r)) {
            $options = explode('|||', $row['options']);

            foreach ($options as $i => $option) {
                $row['optionid'] = $row['pollid'] * 1000 + $i + 1;
                $row['body'] = $option;
                $row['sort'] = $i;

                $ex->writeRow($fp, $row, $exportStructure, $revMappings);

                $rowCount++;
            }
        }
        mysql_free_result($r);
        $ex->writeEndTable($fp);
        $ex->comment("Exported Table: PollOption ($rowCount rows)");

        $pollVote_Map = array(
            'userid' => 'UserID',
            'optionid' => 'PollOptionID',
            'votedate' => array('Column' => 'DateInserted', 'Filter' => 'TimestampToDate')
        );
        $ex->exportTable('PollVote',
            "select pv.*, pollid * 1000 + voteoption as optionid
         from :_pollvote pv", $pollVote_Map);
    }

    /**
     * Filter used by $Media_Map to build attachment path.
     *
     * vBulletin 3.0+ organizes its attachments by descending 1 level per digit
     * of the userid, named as the attachmentid with a '.attach' extension.
     * Example: User #312's attachments would be in the directory /3/1/2.
     *
     * In vBulletin 2.x, files were stored as an md5 hash in the root
     * attachment directory with a '.file' extension. Existing files were not
     * moved when upgrading to 3.x so older forums will need those too.
     *
     * @access public
     * @see ExportModel::_exportTable
     *
     * @param string $Value Ignored.
     * @param string $Field Ignored.
     * @param array $row Contents of the current attachment record.
     * @return string Future path to file.
     */
    public function buildMediaPath($value, $field, $row) {
        if (isset($row['hash']) && $row['hash'] != '') {
            // Old school! (2.x)
            $filePath = $row['hash'] . '.' . $row['extension'];
        } else { // Newer than 3.0
            // Build user directory path
            $n = strlen($row['userid']);
            $dirParts = array();
            for ($i = 0; $i < $n; $i++) {
                $dirParts[] = $row['userid']{$i};
            }

            // 3.x uses attachmentid, 4.x uses filedataid
            $identity = (isset($row['filedataid'])) ? $row['filedataid'] : $row['attachmentid'];

            // If we're exporting blobs, simplify the folder structure.
            // Otherwise, we need to preserve vBulletin's eleventy subfolders.
            $separator = ($this->param('files', false)) ? '' : '/';
            $filePath = implode($separator, $dirParts) . '/' . $identity . '.' . $row['extension'];
        }

        // Use 'cdn' parameter to define path prefix, ex: ?cdn=~cf/
        $cdn = $this->param('cdn', '');

        return $cdn . 'attachments/' . $filePath;
    }

    /**
     * Don't allow image dimensions to creep in for non-images.
     *
     * @param $Value
     * @param $Field
     * @param $Row
     */
    public function buildMediaDimension($value, $field, $row) {
        // Non-images get no height/width
        $ex = $this->ex;
        if ($ex->exists('attachment', array('extension'))) {
            $extension = $row['extension'];
        } else {
            $extension = end(explode('.', $row['filename']));
        }
        if (in_array(strtolower($extension), array('jpg', 'gif', 'png', 'jpeg'))) {
            return null;
        }

        return $value;
    }

    /**
     * Set valid MIME type for images.
     *
     * @access public
     * @see ExportModel::_exportTable
     *
     * @param string $Value Extension from vBulletin.
     * @param string $Field Ignored.
     * @param array $Row Ignored.
     * @return string Extension or accurate MIME type.
     */
    public function buildMimeType($value, $field, $row) {
        switch (strtolower($value)) {
            case 'jpg':
            case 'gif':
            case 'png':
                $value = 'image/' . $value;
                break;
            case 'pdf':
            case 'zip':
                $value = 'application/' . $value;
                break;
            case 'doc':
                $value = 'application/msword';
                break;
            case 'xls':
                $value = 'application/vnd.ms-excel';
                break;
            case 'txt':
                $value = 'text/plain';
                break;
        }

        return $value;
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @access public
     * @see ExportModel::_exportTable
     *
     * @param string $value Current value
     * @param string $field Current field
     * @param array $row Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     */
    public function filterThumbnailData($value, $field, $row) {
        if (strpos(MimeTypeFromExtension(strtolower($row['extension'])), 'image/') === 0) {
            return $value;
        } else {
            return null;
        }
    }

    /**
     * Determine if this usergroup could likely sign in to forum based on its name.
     *
     * @param $Value
     * @param $Field
     * @param $Row
     * @return bool
     */
    public function signInPermission($value, $field, $row) {
        $result = true;
        if (stripos($row['title'], 'unregistered') !== false) {
            $result = false;
        } elseif (stripos($row['title'], 'banned') !== false) {
            $result = false;
        }

        return $result;
    }

    /**
     * Retrieve a value from the vBulletin setting table.
     *
     * @param string $Name Variable for which we want the value.
     * @return mixed Value or FALSE if not found.
     */
    public function getConfig($name) {
        $sql = "select * from :_setting where varname = '$name'";
        $result = $this->ex->query($sql, true);
        if ($row = mysql_fetch_assoc($result)) {
            return $row['value'];
        }

        return false;
    }

    /**
     * @param $Value
     * @param $Field
     * @param $Row
     * @return bool
     */
    public function filterPermissions($value, $field, $row) {
        if (!isset(self::$permissions2[$field])) {
            return 0;
        }

        $column = self::$permissions2[$field][0];
        $mask = self::$permissions2[$field][1];

        $value = ($row[$column] & $mask) == $mask;

        return $value;
    }

    /**
     * @param $ColumnGroups
     * @param $Map
     */
    public function addPermissionColumns($columnGroups, &$map) {
        $permissions2 = array();

        foreach ($columnGroups as $columnGroup => $columns) {
            foreach ($columns as $mask => $columnArray) {
                $columnArray = (array)$columnArray;
                foreach ($columnArray as $column) {
                    $map[$column] = array(
                        'Column' => $column,
                        'Type' => 'tinyint(1)',
                        'Filter' => array($this, 'filterPermissions')
                    );

                    $permissions2[$column] = array($columnGroup, $mask);
                }
            }
        }
        self::$permissions2 = $permissions2;
    }
}

// Closing PHP tag required. (make.php)
?>
