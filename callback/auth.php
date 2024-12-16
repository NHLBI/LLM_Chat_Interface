<?php
/*
Note that we had significant difficulty getting the CA Cert to be found,
and so the connection could not bind. The solution was to edit the file /etc/openldap/ldap.conf     
`TLS_CACERT /etc/pki/tls/certs/ca-bundle.crt`
*/

// Determine the environment dynamically
require_once '../get_config.php';


function authorize($user)
{
    global $config;

    #die($user);
    
    $authorized_users = explode(",",$config['authorized']['users']);
    if (in_array(preg_replace('/@.*/','',$user),$authorized_users)) return true;

    // LDAP server configuration
    $ldap_host = $config['ldap']['ldap_host'];
    $ldap_dn =  $config['ldap']['ldap_dn'];
    $ldap_user =  $config['ldap']['ldap_user'];
    $ldap_password = $config['ldap']['ldap_password'];

    // Connect to the LDAP server
    $ldap_connection = ldap_connect($ldap_host);

    if (!$ldap_connection) {
        die("Could not connect to LDAP server.");
    }

    // Set this to avoid issues with self-signed certificates
    ldap_set_option($ldap_connection, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);

    // Authenticate the user
    if (!ldap_bind($ldap_connection, $ldap_user, $ldap_password)) {
        echo "LDAP bind error: " . ldap_error($ldap_connection). "\n";
        die("Failed to bind to LDAP server.\n");
    }

    // Search for the user and their group memberships
    $search_filter = "(userPrincipalName=".$user.")";
    $result = ldap_search($ldap_connection, $ldap_dn, $search_filter, array('memberof'));

    if (!$result) {
        die("Error in search query: " . ldap_error($ldap_connection));
    }

    $entries = ldap_get_entries($ldap_connection, $result);

    $authorized_groups = explode(";",$config['authorized']['groups']);
    
    // Check and print group memberships
    foreach($authorized_groups as $group) {
        if ($entries["count"] > 0) {
            if (isset($entries[0]['memberof'])) {
                for ($i = 0; $i < $entries[0]['memberof']["count"]; $i++) {
                    if ($entries[0]['memberof'][$i] == $group) {
                        return true;
                        exit;
                    }
                }
            } else {
                #echo "No groups found for user.";
            }
        } else {
            #echo "User not found.";
        }
    }
    return false;

    // Close the connection
    ldap_unbind($ldap_connection);

}
