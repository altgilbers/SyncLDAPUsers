<?php
/*
   Plugin Name: SLU - Sync LDAP Users
   Description: synchronize user accounts between LDAP and WordPress
   Author: Ian Altgilbers  ian@altgilbers.com
   Source: https://github.com/altgilbers/SyncLDAPUsers
   Version: 0.5
 */


add_action('network_admin_menu', 'SLU_plugin_setup_menu');
function SLU_plugin_setup_menu(){
        add_menu_page( $page_title='SLU Plugin Page',
                        $menu_title='SLU Options',
                        $capability='manage_network',
                        $menu_slug='slu-options',
                        $function='slu_init' );
}


// The settings API doesn't fully work with plugins on the network admin page,
// the form UI portion works, but the server-side processing portion does not
add_action('admin_init','SLU_admin_init');
function SLU_admin_init(){

        add_settings_section($id='slu_ldap_connection_settings',
                        $title='LDAP Connection Settings',
                        $callback='',
                        $page='slu-options' );

        register_setting($option_group='slu_ldap_connection_group',
			$option_name='slu_ldap_host');
        register_setting($option_group='slu_ldap_connection_group',
			$option_name='slu_ldap_rdn');
        register_setting($option_group='slu_ldap_connection_group',
			$option_name='slu_ldap_pass');
        register_setting($option_group='slu_ldap_connection_group',
			$option_name='slu_cron_enable');


        add_settings_field($id='slu_ldap_host',
			$title='LDAP host',
			$callback='slu_ldap_host_cb',
			$page='slu-options',
			$section='slu_ldap_connection_settings');
        add_settings_field($id='slu_ldap_rdn',
			$title='LDAP user account',
			$callback='slu_ldap_rdn_cb',
			$page='slu-options',
			$section='slu_ldap_connection_settings');
        add_settings_field($id='slu_ldap_pass',
			$title='LDAP user password',
			$callback='slu_ldap_pass_cb',
			$page='slu-options',
			$section='slu_ldap_connection_settings');
        add_settings_field($id='slu_cron_enable',
			$title='Enable cron task',
			$callback='slu_cron_enable_cb',
			$page='slu-options',
			$section='slu_ldap_connection_settings');
}


function slu_ldap_host_cb(){
	echo "<input type='text' name='slu_ldap_host' value='".get_site_option('slu_ldap_host')."'/>";
}
function slu_ldap_rdn_cb(){
	echo "<input type='text' name='slu_ldap_rdn' value='".get_site_option('slu_ldap_rdn')."'/>";
}
function slu_ldap_pass_cb(){
	echo "<input type='password' name='slu_ldap_pass' value='".get_site_option('slu_ldap_pass')."'/>";
}
function slu_cron_enable_cb(){
	echo "<input type='checkbox' name='slu_cron_enable' ";
	if(get_site_option('slu_cron_enable')=="true")
		echo "checked";
	echo ">";
}



// this action runs when /wp-admin/network/edit.php is called with ?action=slu-options
// could use some validation...
add_action('network_admin_edit_slu-options', 'slu_save_network_options');
function slu_save_network_options(){
	
	$redirect_query_string_array=array( 'page' => 'slu-options');
        $error_msg="";

	// superadmins shouldn't ever see this, but if somehow they do, bail...
	if(!is_super_admin()){
		exit;
	}

        sync_log("Saving network options...");

	if(isset($_POST["slu_ldap_host"])){
		update_site_option("slu_ldap_host",$_POST["slu_ldap_host"]);
	}
	if(isset($_POST["slu_ldap_rdn"])){
		update_site_option("slu_ldap_rdn",$_POST["slu_ldap_rdn"]);
	}
	if(isset($_POST["slu_ldap_pass"])){
		update_site_option("slu_ldap_pass",$_POST["slu_ldap_pass"]);
	}
	else{
		sync_log("pass not set");
	}

	if(isset($_POST["slu_cron_enable"])){
		sync_log("slu_cron_enable=".$_POST["slu_cron_enable"]);
                update_site_option("slu_cron_enable","true");
	        if (wp_next_scheduled ( 'slu_sync_event' )) {
                	wp_clear_scheduled_hook('slu_sync_event');
                }
		wp_schedule_event(time(), 'hourly', 'slu_sync_event');
	}
	else
	{
		update_site_option("slu_cron_enable","false");
		wp_clear_scheduled_hook('slu_sync_event');
	}
	
	//check LDAP connection info:
	$ldap_host=get_site_option('slu_ldap_host');
	$ldap_rdn=get_site_option('slu_ldap_rdn');
	$ldap_password=get_site_option('slu_ldap_pass');

	sync_log("verifying connection to ".$ldap_host);
	$L=ldap_connect("ldaps://".$ldap_host);
	$R=ldap_bind($L,$ldap_rdn,$ldap_password);
	if(!$R){
		$redirect_query_string_array['error']=urlencode(ldap_error($L));
	}
	$redirect_url=add_query_arg($redirect_query_string_array,
        (is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ))
	);

	sync_log("redirecting to: ".$redirect_url);
	wp_redirect($redirect_url);
	
	//must exit, otherwise another redirect will trump the one we set here.
	exit;
}


function slu_init(){

	echo "<h1>Sync LDAP Users</h1>";
	if($_GET['updated']=="true")
		echo "<div id='message' class='updated'>updated</div>";
	if(isset($_GET['error']))
		echo "<div id='message' class='error'>".urldecode($_GET['error'])."</div>";

	?>
	
	<form action="/wp-admin/network/edit.php?action=slu-options" method="post">
	<?php
	settings_fields('slu_ldap_connection_group');
	do_settings_sections('slu-options');
	submit_button();
	?>
	</form>
	
	<br/>
	<p>LDAP records synced up through:
	<?php echo get_site_option('slu_updated_through','uninitialized');?></p><?php 
	echo "<p>Next scheduled run at approx: ".date("Y-m-d H:i:s T",wp_next_scheduled ( 'slu_sync_event' ))."</p>";

	// you can make the process start over with slu-reset
        if(isset($_GET['slu-reset']))
        {
                delete_site_option('slu_updated_through');
	}
	// you can force a non-cron'ed run wish slu-process
	if(isset($_GET['slu-process']))
	{
		slu_sync_users();
	}
}



register_activation_hook(__FILE__, 'slu_activate');
register_deactivation_hook(__FILE__, 'slu_deactivate');

function slu_activate() {
         sync_log("Activating plugin.");
}

function slu_deactivate(){
	sync_log("Deactivating Plugin.. removing scheduled task");
	wp_clear_scheduled_hook('slu_sync_event');
	delete_site_option('slu_ldap_pass');
	delete_site_option('slu_ldap_rdn');
	delete_site_option('slu_ldap_host');
	delete_site_option('slu_updated_through');

	// should clean up user_meta settings as well

}


// define action that is called by wp-cron
add_action('slu_sync_event', 'slu_sync_users');


// This function is the where the syncing actually happens
function slu_sync_users(){ 

        sync_log("----------------------------------------");
        sync_log("begin LDAP user sync");
        sync_log("----------------------------------------");

	// this is to prevent WordPress from sending emails to users whose email addresses have changed
	add_filter( 'send_email_change_email', '__return_false' );

        // timezone set needed to get formatted time for LDAP filter
        date_default_timezone_set('UTC');

	$ldap_host=get_site_option('slu_ldap_host');
	$ldap_rdn=get_site_option('slu_ldap_rdn');
	$ldap_password=get_site_option('slu_ldap_pass');
	$ldap_conn=ldap_connect("ldaps://".$ldap_host)
		or die("Could not connect to $ldap_host");
	if (ldap_bind($ldap_conn,$ldap_rdn,$ldap_password)){
		sync_log("LDAP bind successful bind\n");
	}
	else{
		sync_log("bind failed:".ldap_error($ldap_conn));
		die("Could not bind");
	}

        // Determine where our last sync ended, 
        $sync_window_start=get_site_option('slu_updated_through');
        if($sync_window_start==false)  // first run, so we'll set the start to the begining of time and the end to two years ago
        {
                sync_log("Looks like a first run... (or a reset)...");
                $sync_window_start="19700101000000";
                $sync_window_end=date("YmdHis",time()-3600*24*365*2);
        }
        else
        {
                $sync_window_start_time=convertLdapTimeStamp($sync_window_start);
                $sync_window_end_time=$sync_window_start_time+3600*24*30;   // we grab 30 days worth of updates (this is arbitrary, but I wanted to avoid grabbing multi-thousands of records for each run).
                $sync_window_end=date("YmdHis",$sync_window_end_time);
        }

	$uid_filter="uid=*";
	//$mail_filter="mail=*";
	$start_filter="modifyTimeStamp>=".$sync_window_start.".0Z";
	$end_filter="modifyTimeStamp<=".$sync_window_end.".0Z";

        $filters=array();
	array_push($filters, $uid_filter);
	// excluding the mail filter, because we need to see everyone in order to mark old accounts as inactive
	//array_push($filters, $mail_filter);
	array_push($filters, $start_filter);
	array_push($filters, $end_filter);

	// Fields we get from LDAP - the last one is Tufts specific
	$fields=array("uid","mail","givenName","sn","modifyTimeStamp","tuftsEduAtamsEligibility");
	
	$filter="(&";
	foreach($filters as $f)
		$filter.="(".$f.")";
	$filter.=")";
	
	echo "<p>filter: ".$filter."</p>\n";
	$begin=$before=microtime(true);

	$result=ldap_search($ldap_conn,$base_dn,$filter,$fields);
	$entries=ldap_get_entries($ldap_conn, $result);
	$after=microtime(true);

	echo "<h3>".$entries["count"]." LDAP accounts returned</h3>";
	echo "<p>".($after-$before)." seconds to get LDAP results.</p>";

	sync_log($entries["count"]." entries returned in ".($after-$before)."s from LDAP for filter: ".$filter);
	$ldap_result_count=$entries["count"];
	//unset count, because it gets in the way when sorting/processing the entries
	unset($entries['count']);
	$modified_users=0;
	$added_users=0;
	$untouched_users=0;
	$failed_users=0;
	$bailed=false;

	// important to sort these records, so we process them sequentially according to modifyTimeStamp
	usort($entries,"ldap_sort_comparer");
	$before=microtime(true);
	// Loop through all entries returned for our filter   foreach would be better here...
	foreach ($entries as $entry)
	{
		$user=array();
		$update_user=array();

		$ldap_uid=$entry["uid"][0];
		$ldap_givenName=$entry["givenname"][0];  //beware of PHP LDAP downcasing attribute names
		$ldap_sn=$entry["sn"][0];
		$ldap_mail=$entry["mail"][0];
		$ldap_modifyTimeStamp=$entry["modifytimestamp"][0];
		$ldap_eligibility=$entry["tuftseduatamseligibility"][0];  // beware of PHP LDAP downcasing attribute names

		if (preg_match("/^former*|locked|ineligible/",$ldap_eligibility))
			$ldap_user_status="inactive";
		else
			$ldap_user_status="active";

		// check to see if user currently exists in the WP DB
		$user=get_user_by('login',$ldap_uid);
		$dirty=FALSE;
		$log_message="";
		if($user)   // if user already exists, we can update fields to match LDAP record
		{
			// if a user has a "former*", locked, or ineligible  eligibility status from LDAP, we'll record that in 
			// a user meta option "slu_user_status"

	                $slu_user_status=get_user_meta($user->ID,'slu_user_status',true);
			if($slu_user_status !== $ldap_user_status)
			{
				update_user_meta($user->ID,'slu_user_status',$ldap_user_status);
				update_user_meta($user->ID,'slu_user_update_time',$ldap_modifyTimeStamp);
				sync_log($user->user_login." status updated to: ".$ldap_user_status);
			}

			if ($user->user_email!==$ldap_mail && !empty($ldap_mail) )  // don't update the email if it's blank
			{
                                $update_user[user_email]=$ldap_mail;
				$log_message.="email mismatch ";
				$dirty=TRUE;
			} 
			if ($user->first_name!==$ldap_givenName)
			{
                                $update_user[first_name]=$ldap_givenName;
                                $log_message.="givenName mismatch ";
				$dirty=TRUE;
			}
			if ($user->last_name!==$ldap_sn)
			{
                                $update_user[last_name]=$ldap_sn;
                                $log_message.="last_name mismatch ";
				$dirty=TRUE;
			}
			if($dirty)
			{
				$update_user[ID]=$user->ID;
				$ret_code=wp_update_user($update_user);
				if(is_wp_error($ret_code))
				{
					echo "<p>error_message:".$ret_code->get_error_message()."</p>";
					sync_log($user->user_login." not updated: ".$ret_code->get_error_message());
					$failed_users++;
				}
				else
				{
					sync_log($user->user_login." updated - ".$log_message);
					echo "<p>".$user->user_login." updated - ".$log_message."</p>\n";
					update_user_meta($update_user[ID],'slu_user_update_time',$ldap_modifyTimeStamp);
					$modified_users++;
				}
			}
			else
			{
				$untouched_users++;
			}		
		}
		else  // user doesn't exist in WP DB
		{
			if(!empty($ldap_mail))
			{
				echo "<p>".$entry["uid"][0]." has no WP account</p>\n";
				$new_user=array(
						"user_login"=>$ldap_uid,
						"user_email"=>$ldap_mail,
						"first_name"=>$ldap_givenName,
						"last_name"=>$ldap_sn);
				$new_user_id=wp_insert_user($new_user);
				if ( ! is_wp_error($new_user_id) )
				{
				        $added_users++;
					sync_log($ldap_uid." created");
      		                        update_user_meta($new_user_id,'slu_user_status',$ldap_user_status);
					update_user_meta($new_user_id,'slu_user_update_time',$ldap_modifyTimeStamp);
				}
				else
				{
					sync_log($ldap_uid." not added: ".$new_user_id->get_error_message());
				}
			}
			else
			{
				sync_log($ldap_uid." not added:  No email address provided by LDAP");
	
			}
		}

		// bookkeeping.... keep track of how far we got, so we know where to pick up next time.
		update_site_option('slu_updated_through',$ldap_modifyTimeStamp);


		// if a particular batch of users is large and might not finish before execution timeout, we can gracefully stop and pick up next time
		if(microtime(true)-$before > ini_get('max_execution_time')-2){
			sync_log("bailing before max_execution_time");
			$bailed=true;
			break;
		}
	}
	// if there were no entries, we need to update 'slu_updated_through' to the end-time, since it wouldn't have been updated by a user-update
	if($ldap_result_count==0  && convertLdapTimeStamp($sync_window_end)<time())
	{
		sync_log("no entries between ".$sync_window_start." and ".$sync_window_end.".");
                update_site_option('slu_updated_through',$sync_window_end);
	}

	sync_log($added_users." users added");
	sync_log($modified_users." users modified");
	sync_log($untouched_users." users untouched");
	sync_log($failed_users." users failed to update");


	//  If we're not caught up to current day, we'll reschedule another run in 10 seconds (10 seconds just in case 
	//  there are long periods with no LDAP updates, which couldsome problems...
	$updated_through=get_site_option('slu_updated_through');
	//sync_log("updated_through: ".convertLdapTimeStamp($updated_through)." time()-1day: ".(time()-3600*24));
	if( convertLdapTimeStamp($updated_through) < time()-3600*24 )
	{
		sync_log("Not finished..  rescheduling task to resume processing..");
		if (wp_next_scheduled ( 'slu_sync_event' )) {
			wp_clear_scheduled_hook('slu_sync_event');
			wp_schedule_event(time()+10, 'hourly', 'slu_sync_event');
		}
	}
	else
	{
		sync_log("complete - next run in an hour");
	}

}

// logging to a separate file with timestamps
function sync_log($msg)
{
 	$sync_log_location=__DIR__."/logs/slu_ldap_sync.log";
	error_log("[".date("Y-m-d H:i:s T")."] - ".$msg."\n",3,$sync_log_location);
}


function ldap_sort_comparer($a,$b)
{
//  just strncmp the timestamps of the records...  the format of the date allows for string comparison
	return strncmp($a['modifytimestamp'][0],$b['modifytimestamp'][0],14);
}

function convertLdapTimeStamp($timestamp){
        //PHP script to convert a timestamp returned from an LDAP query into a Unix timestamp 
        // The date as returned by LDAP in format yyyymmddhhmmsst
        $date = $timestamp;

        // Get the individual date segments by splitting up the LDAP date
        $year = substr($date,0,4);
        $month = substr($date,4,2);
        $day = substr($date,6,2);
        $hour = substr($date,8,2);
        $minute = substr($date,10,2);
        $second = substr($date,12,2);

        // Make the Unix timestamp from the individual parts
        $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
	return $timestamp;
    }

// adding user status to network users listing
add_filter('wpmu_users_columns', 'add_user_status');
function add_user_status($columns) {
    $columns['status'] = 'Status';
    return $columns;
}
add_filter('manage_users_custom_column','add_user_status_column',10,3);
function add_user_status_column($output,$column_name,$user_id)
{
	$status=get_user_meta($user_id,'slu_user_status',true);
	if(empty($status))
		$status="undefined";
        return "<strong>".$status."</strong>";
}


?>
