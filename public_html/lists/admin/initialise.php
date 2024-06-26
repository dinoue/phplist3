<?php

require_once dirname(__FILE__).'/accesscheck.php';

include dirname(__FILE__).'/structure.php';

function output($message)
{
    if ($GLOBALS['commandline']) {
        @ob_end_clean();
        echo strip_tags($message)."\n";
        ob_start();
    } else {
        echo $message;
        flush();
        @ob_end_flush();
    }
    flush();
}
cl_output(s('Initialising phpList database structure.'));

$success = 1;

## fall back to environment variables (mostly for CLI)
if (!isset($_REQUEST['adminname'])) {
    $_REQUEST['adminname'] = getenv('ADMIN_NAME');
}
if (!isset($_REQUEST['orgname'])) {
    $_REQUEST['orgname'] = getenv('ORGANISATION_NAME');
}
if (!isset($_REQUEST['adminpassword'])) {
    $_REQUEST['adminpassword'] = getenv('ADMIN_PASSWORD');
}
if (!isset($_REQUEST['adminemail'])) {
    $_REQUEST['adminemail'] = getenv('ADMIN_EMAIL');
}

## require some variables on CLI
if ($GLOBALS['commandline']) {
  if (empty($_REQUEST['adminname'])) {
      $_REQUEST['adminname'] = 'admin';
  }
  if (empty($_REQUEST['orgname'])) {
      $_REQUEST['orgname'] = s('Organisation Name');
  }
  if (empty($_REQUEST['adminpassword'])) {
      output(s('Admin password not set, cannot continue'));
      cl_output(s('set ADMIN_PASSWORD environment variable'));
      return;
  }
  if (empty($_REQUEST['adminemail'])) {
      output(s('Admin email not set, cannot continue'));
      cl_output(s('set ADMIN_EMAIL environment variable'));
      return;
  }
  if ($GLOBALS['commandline'] && !is_email($_REQUEST['adminemail'])) {
    output(s('Unable to validate email address for admin: '.strip_tags($_REQUEST['adminemail'])));
    return;
  }
}

$force = (!empty($_GET['force']) && $_GET['force'] == 'yes') || isset($cline['f']);

if ($force) {
    foreach ($DBstruct as $table => $val) {
        if ($table == 'attribute' && Sql_Table_Exists('attribute')) {
            $req = Sql_Query("select tablename from {$tables['attribute']}");
            while ($row = Sql_Fetch_Row($req)) {
                Sql_Query('drop table if exists '.$table_prefix.'listattr_'.$row[0]);
            }
        }
        Sql_Query('drop table if exists '.$tables[$table]);
    }
    if (!$GLOBALS['commandline']) {
      session_destroy();
      Redirect('initialise&firstinstall=1');
      exit;
    }
}
@ob_end_flush();

if (!$GLOBALS['commandline'] && empty($_SESSION['hasconf']) && !empty($_REQUEST['firstinstall']) && (empty($_REQUEST['adminemail']) || strlen($_REQUEST['adminpassword']) < 8)) {
    $output = '<noscript>';
    $output .= '<div class="error">'.s('To install phpList, you need to enable Javascript').'</div>';
    $output .= '</noscript>';

    if ($_SESSION['adminlanguage']['iso'] != $GLOBALS['default_system_language'] &&
        in_array($_SESSION['adminlanguage']['iso'], array_keys($GLOBALS['LANGUAGES']))
    ) {
        $output .= '<div class="info error">'.s('The default system language is different from your browser language.').'<br/>';
        $output .= s('You can set <pre>$default_system_language = "%s";</pre> in your config file, to use your language as the fallback language.',
                $_SESSION['adminlanguage']['iso']).'<br/>';
        $output .= s('It is best to do this before initialising the database.');
        $output .= '</div>';
    }

    $output .= '<form method="post" action="" class="configForm" id="initialiseform">';
    $output .= '<fieldset><legend>'.s('phpList initialisation').' </legend>
    <input type="hidden" name="firstinstall" value="1" />';
    $output .= '<input type="hidden" name="page" value="initialise" />';
    $output .= '<label for="adminname">'.s('Please enter your name.').'</label>';
    $output .= '<div class="field"><input type="text" name="adminname" class="error missing" value="'.htmlspecialchars($_REQUEST['adminname']).'" /></div>';
    $output .= '<label for="orgname">'.s('The name of your organisation').'</label>';
    $output .= '<input type="text" name="orgname" value="'.htmlspecialchars($_REQUEST['orgname']).'" />';
    $output .= '<label for="adminemail">'.s('Please enter your email address.').'</label>';

    $output .= '<input type="text" name="adminemail" value="'.htmlspecialchars($_REQUEST['adminemail']).'" />';
    $output .= s('The initial <i>login name</i> will be').' "admin"'.'<br/>';
    $output .= '<label for="adminpassword">'.s('Please enter the password you want to use for this account.').' ('.$GLOBALS['I18N']->get('minimum of 8 characters.').')</label>';
    $output .= '<input type="text" name="adminpassword" value="" id="initialadminpassword" /><br/><br/>';
    $output .= '<input type="submit" value="'.s('Continue').'" id="initialisecontinue" disabled="disabled" />';
    $output .= '</fieldset></form>';
    output($output);
    return;
}

//var_dump($GLOBALS['plugins']);exit;

if ($GLOBALS['commandline'] && $_SESSION['hasconf'] && empty($force)) {
  cl_output(s('Already initialised. Use -f to force'));
  return;
}

output('<h3>'.s('Creating tables')."</h3><br />");
foreach ($DBstruct as $table => $val) {
    if ($force) {
        if ($table == 'attribute' &&  Sql_Table_exists('attribute')) {
            $req = Sql_Query("select tablename from {$tables['attribute']}");
            while ($row = Sql_Fetch_Row($req)) {
                Sql_Query("drop table if exists $table_prefix"."listattr_$row[0]", 1);
            }
        }
        Sql_query("drop table if exists $tables[$table]");
        unset($_SESSION["dbtables"]);
    }
    $query = "CREATE TABLE $tables[$table] (\n";
    foreach ($DBstruct[$table] as $column => $struct) {
        if (preg_match('/index_\d+/', $column)) {
            $query .= 'index '.$struct[0].',';
        } elseif (preg_match('/unique_\d+/', $column)) {
            $query .= 'unique '.$struct[0].',';
        } else {
            $query .= "$column ".$struct[0].',';
        }
    }
    // get rid of the last ,
    $query = substr($query, 0, -1);
    $query .= "\n) default character set utf8";

    if (!empty($GLOBALS['mysql_database_engine'])) {
      $query .= ' engine '.$GLOBALS['mysql_database_engine'];
    }

    // submit it to the database
    output(s('Initialising table')." <b>$table</b>");
    if (!$force && Sql_Table_Exists($tables[$table])) {
        Error(s('Table already exists').'<br />');
        output( '... '.s('failed')."<br />");
        $success = 0;
    } else {
        $res = Sql_Query($query, 0);
        $error = Sql_Has_Error($database_connection);
        $success = $force || ($success && !$error);
        if (!$error || $force) {
            if ($table == 'admin') {
                // create a default admin
                $_SESSION['firstinstall'] = 1;
                $adminemail = $_REQUEST['adminemail'];
                $adminpass = $_REQUEST['adminpassword'];
                Sql_Query(sprintf('insert into %s (loginname,namelc,email,created,password,passwordchanged,superuser,disabled)
                    values("%s","%s","%s",now(),"%s",now(),%d,0)',
                    $tables['admin'], 'admin', 'admin', sql_escape($adminemail), encryptPass($adminpass), 1));

                //# let's add them as a subscriber as well
                $userid = addNewUser($adminemail, $adminpass);
                Sql_Query(sprintf('update %s set confirmed = 1 where id = %d', $tables['user'], $userid));

                /* to send the token at the end, doesn't work yet
                $adminid = Sql_Insert_Id();
                */
            } elseif ($table == 'task') {
                foreach ($system_pages as $type => $pages) {
                    foreach ($pages as $page => $access_level) {
                        Sql_Query(sprintf('replace into %s (page,type) values("%s","%s")',
                            $tables['task'], $page, $type));
                    }
                }
            }

            output( '... '.s('ok')."<br />");
        } else {
            output( '... '.s('failed')."<br />\n");
        }
    }
}
//https://mantis.phplist.com/view.php?id=16879 make sure the new settings are saved
if ($success) {
    $_SESSION['hasconf'] = true;
}

//# initialise plugins that are already here
foreach ($GLOBALS['plugins'] as $pluginName => $plugin) {
    output( s('Initialise plugin').' '.$pluginName.'<br/>');
    if (method_exists($plugin, 'initialise')) {
        $plugin->initialise();
    }
    SaveConfig(md5('plugin-'.$pluginName.'-initialised'), time(), 0);
}

if ($success) {
    output( s('Setting default configuration').'<br/>');
    // mark the database to be our current version
    output('<strong>'.s('Admin =').'</strong> '.$_REQUEST['adminname'].'<br/>');
    output('<strong>'.s('Admin Email =').'</strong> '.$adminemail.'<br/>');
    SaveConfig('version', VERSION, 0);
    SaveConfig('admin_address', $adminemail, 1);
    SaveConfig('message_from_name', strip_tags($_REQUEST['adminname']), 1);
    SaveConfig('campaignfrom_default', "$adminemail ".strip_tags($_REQUEST['adminname']));
    SaveConfig('notifystart_default', $adminemail);
    SaveConfig('notifyend_default', $adminemail);
    SaveConfig('report_address', $adminemail);
    SaveConfig('message_from_address', $adminemail);
    SaveConfig('message_from_name', strip_tags($_REQUEST['adminname']));
    SaveConfig('message_replyto_address', $adminemail);
    SaveConfig('secret', bin2hex(random_bytes(20)));
    SaveConfig('lastcheckupdate', date('m/d/Y h:i:s', time()), 0, true);

    if (!empty($_REQUEST['orgname'])) {
        SaveConfig('organisation_name', strip_tags($_REQUEST['orgname']), 1);
        SaveConfig('campaignfrom_default', "$adminemail ".strip_tags($_REQUEST['orgname']));
        SaveConfig('message_from_name', strip_tags($_REQUEST['orgname']));
    } elseif (!empty($_REQUEST['adminname'])) {
        SaveConfig('organisation_name', strip_tags($_REQUEST['adminname']), 1);
    } else {
        SaveConfig('organisation_name', strip_tags($_REQUEST['adminemail']), 1);
    }
    // add a draft campaign for invite plugin
    addInviteCampaign(1);
    // add a testlist
    $info = s('List for testing');
    $result = Sql_query("insert into {$tables['list']} (name,description,entered,active,owner) values(\"test\",\"$info\",now(),0,1)");
    $info = s('Sign up to our newsletter');
    $result = Sql_query("insert into {$tables['list']} (name,description,entered,active,owner) values(\"newsletter\",\"$info\",now(),1,1)");

    //# add the admin to the lists
    Sql_Query(sprintf('insert into %s (listid, userid, entered) values(%d,%d,now())', $tables['listuser'], 1, $userid));
    Sql_Query(sprintf('insert into %s (listid, userid, entered) values(%d,%d,now())', $tables['listuser'], 2, $userid));

    $uri = $_SERVER['REQUEST_URI'];
    $uri = str_replace('?'.$_SERVER['QUERY_STRING'], '', $uri);
    $body =
        'Version: '.VERSION."\r\n"
        .' Url: '
        .$_SERVER['SERVER_NAME']
        .$uri
        ."\r\n";
    printf('<p class="information">'
        .$GLOBALS['I18N']->get('Success')
        .': <a class="button" href="mailto:info@phplist.com?subject=Successful installation of phplist&amp;body=%s">'
        .$GLOBALS['I18N']->get('Tell us about it')
        .'</a>. </p>', $body);
    //printf('<p class="information">
    //'.$GLOBALS['I18N']->get("Please make sure to read the file README.security that can be found in the zip file.").'</p>');
    echo subscribeToAnnouncementsForm($_REQUEST['adminemail']);

    // make sure the 0 template has the powered by image
    $query = sprintf('insert into %s (template, mimetype, filename, data, width, height) values (0, "image/png", "powerphplist.png", "%s", 70, 30)',
        $GLOBALS['tables']['templateimage'], $newpoweredimage);
    Sql_Query($query);
    echo '<div id="continuesetup" style="display:none;" class="fleft">'.$GLOBALS['I18N']->get('Continue with').' '.PageLinkButton('setup',
            $GLOBALS['I18N']->get('phpList Setup')).'</div>';

    unset($_SESSION['hasI18Ntable']);

    //# load language files
    // this is too slow
    $GLOBALS['I18N']->initFSTranslations();
} else {
    echo '<div class="initialiseOptions"><ul><li>'.s('Maybe you want to').' '.PageLinkButton('upgrade',
            s('Upgrade')).' '.s('instead?').'</li>
    <li>' .PageLinkButton('initialise', s('Force Initialisation'),
            'force=yes').' '.s('(will erase all data!)').' '."</li></ul></div>\n";
}