<?php
/*
   Plugin Name: SLU - Sync LDAP Users
   Description: synchronize user accounts between LDAP and WordPress
   Author: Ian Altgilbers
   Version: 0.1
 */


add_action('network_admin_menu', 'SLU_plugin_setup_menu');

function SLU_plugin_setup_menu(){
	add_menu_page( 'SLU Plugin Page', 'SLU Options', 'manage_network', 'slu-options', 'slu_init' );
}


function slu_error_log($message){
	if(True)
		error_log($message);
	else
		error_log($message);

}

function slu_init(){
	echo "<h1>Sync LDAP Users</h1>";
	slu_sync_users();
}

function slu_sync_users(){ 

	// get ldap connection details/credentials.   In a separate file for now, moving to options table
	include 'include.php';


	$ldap_conn=ldap_connect("ldaps://".$ldap_host)
		or die("Could not connect to $ldaphost");

	if (ldap_bind($ldap_conn,$ldap_rdn,$ldap_password))
	{
		echo "successful bind\n";
	}
	else
	{
		echo "bind failed\n";
	}

	// timezone set needed to get formatted time for LDAP filter
	date_default_timezone_set('UTC');
	echo "<p>time: ".time()."</p>\n";
	echo "<p>formatted: ".date("YmdHis",time()-7200)."</p>\n";
	//$filter="modifyTimeStamp>=".date("YmdHis",time()-3600).".0Z";
	$fields=array("uid","mail","givenName","sn");
	echo "<p>filter: ".$filter."</p>\n";


	$result=ldap_search($ldap_conn,$base_dn,$filter,$fields);
	$entries=ldap_get_entries($ldap_conn, $result);


	echo "<h3>LDAP accounts updated in the last hour:</h3>";
	for ($i=0; $i<$entries["count"]; $i++)
	{
		echo "<p>".$entries[$i]["uid"][0]." found in LDAP</p>\n";
		$ldap_uid=$entries[$i]["uid"][0];
		$ldap_givenName=$entries[$i]["givenname"][0];  //beware of PHP LDAP downcasing attribute names
		$ldap_sn=$entries[$i]["sn"][0];
		$ldap_mail=$entries[$i]["mail"][0];
		echo "<p>".print_r($entries[$i])."</p>";

		// check to see if user currently exists in the WP DB
		$user=get_user_by('login',$entries[$i]["uid"][0]);
		if($user)
		{
			echo "<p>User first name = ".$user->first_name."</p>/n";
			if ($user->user_email!==$ldap_mail || $user->first_name!==$ldap_givenname)
			{
				echo "<p>email mismatch..   ".$user->user_email."!=".$ldap_mail."</p>\n";
				$user->user_email=$ldap_mail;
				$user->first_name=$ldap_givenName;
				wp_insert_user($user);
                                slu_error_log(print_r($user));
			}
			else
			{
				echo "<p>email match..   ".$user->user_email."==".$ldap_mail."</p>\n";
				echo "<p>".print_r($user)."</p>";
			}		
		}
		else  // user doesn't exist in WP DB
		{
			echo "<p>".$entries[$i]["uid"][0]." has no WP account</p>\n";
			$new_user=array(
					"user_login"=>$ldap_uid,
					"user_email"=>$ldap_mail,
					"first_name"=>"$ldap_givenName",
					"last_name"=>$ldap_sn,
					"password"=>wp_generate_password(16)
				       );
			$user_id=wp_insert_user($new_user);
			if ( ! is_wp_error( $user_id ) ) {
				echo "User created : ". $user_id;
				slu_error_log(print_r($new_user));
			}

		}
	}




}
?>
