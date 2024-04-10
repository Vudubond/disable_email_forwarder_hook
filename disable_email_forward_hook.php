#!/usr/local/cpanel/3rdparty/bin/php -q
<?php

/**
 * @version    2.0.0
 * @package    Disable Email Filters
 * @author     Vudubond
 * @url
 * @copyright
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 */

// file - /usr/local/src/vudubond2/disable_email_filter_hook.php

// Save hook action scripts in the /usr/local/cpanel/3rdparty/bin directory.
// Scripts must have root:root ownership and 755 permissions.
// Hook modules execute as part of the cPanel Server daemon (cpsrvd).
// Hook action code as a script cannot access cPanel environment variables.

// PHP Log
// /usr/local/cpanel/logs/error_log

// Registered hooks
// /usr/local/cpanel/bin/manage_hooks list

// Toggle debug mode
// Debug Mode option in the Development section of WHM's Tweak Settings (WHM >> Home >> Server Configuration >> Tweak Settings)

// Install
// mkdir /usr/local/src/vudubond2
// cd /usr/local/src/vudubond2;
// https://raw.githubusercontent.com/Vudubond/disable_email_filter_hook/master/disable_email_filter_hook.php
// copy file to folder
// chown root:root /usr/local/src/vudubond2/disable_email_filter_hook.php;
// chmod 755 /usr/local/src/vudubond2/disable_email_filter_hook.php;
// /usr/local/cpanel/bin/manage_hooks add script /usr/local/src/vudubond2/disable_email_filter_hook.php
// create and populate the file /etc/forwarder_blocked_domains.txt
// touch /etc/forwarder_blocked_domains.txt

// Uninstall
// /usr/local/cpanel/bin/manage_hooks delete script /usr/local/src/vudubond2/disable_email_filter_hook.php

// Embed hook attribute information
function describe()
{
    $api2_add_hook = array(
        'blocking' => 1,
        'category' => 'Cpanel',
        'event'    => 'Api2::Email::storefilter',
        'stage'    => 'pre',
        'hook'     => '/usr/local/src/vudubond2/disable_email_filter_hook.php --add_api2',
        'exectype' => 'script',
    );

    $uapi_add_hook = array(
        'blocking' => 1,
        'category' => 'Cpanel',
        'event'    => 'UAPI::Email::store_filter',
        'stage'    => 'pre',
        'hook'     => '/usr/local/src/vudubond2/disable_email_filter_hook.php --add_uapi',
        'exectype' => 'script',
    );

    return array($api2_add_hook, $uapi_add_hook);
}

// Process data from STDIN
function get_passed_data()
{
    // Get input from STDIN
    $raw_data = '';
    $stdin_fh = fopen('php://stdin', 'r');
    if (is_resource($stdin_fh)) {
        stream_set_blocking($stdin_fh, 0);
        while (($line = fgets($stdin_fh, 1024)) !== false) {
            $raw_data .= trim($line);
        }
        fclose($stdin_fh);
    }

    // Process and JSON-decode the raw output
    if ($raw_data) {
        $input_data = json_decode($raw_data, true);
    } else {
        $input_data = array('context' => array(), 'data' => array(), 'hook' => array());
    }

    // Return the output
    return $input_data;
}

// Cpanel::UAPI::Email::add_forwarder
// We strongly recommend that you use UAPI::Email::add_forwarder instead of Api2::Email::addforward
// https://documentation.cpanel.net/display/DD/UAPI+Functions+-+Email%3A%3Aadd_forwarder
function add_uapi($input = array())
{

    //error_log("add_uapi\n");
    //error_log(print_r($input, true));

    return add($input, 'uapi');
}

// Cpanel::Api2::Email::addforward
// We strongly recommend that you use UAPI::Email::add_forwarder instead of Api2::Email::addforward
// https://documentation.cpanel.net/display/DD/cPanel+API+2+Functions+-+Email%3A%3Aaddforward
function add_api2($input = array())
{

    //error_log("add_api2\n");
    //error_log(print_r($input, true));

    return add($input, 'api2');
}

function add($input, $api_type)
{
    $api_function = 'uapi' === $api_type ? 'UAPI::Email::store_filter' : 'Api2::Email::storefilter';
    $input_context = $input['context'];
    $input_args = $input['data']['args'];
    $domain = $input_args['account'];
    $action_api = $input_context['event'];
    $action_add = 'deliver'; // Assuming all destinations are for delivery

    // Initialize variables for result and message
    $result = 1; // Assume success initially
    $message = '';

    // Iterate over destination variables
    for ($i = 1; isset($input_args["dest$i"]); $i++) {
        $email_to = trim($input_args["dest$i"]);

        // Check if the email is valid
        if (!filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
            //$result = 0;
            //$message .= "Invalid email address for destination $i.\n";
            continue; // Move to the next destination
        }

        // Check if the destination domain matches the account domain
        $sanitized_email_to = filter_var($email_to, FILTER_SANITIZE_EMAIL);
        $email_to_domain = array_pop(explode('@', $sanitized_email_to));

        // Check if the destination domain is allowed
        $baddomains = explode("\n", file_get_contents('/etc/forwarder_blocked_domains.txt'));
        if (in_array($sanitized_email_to, $baddomains)) {
            $result = 0;
            $message .= "Forwarding to $sanitized_email_to is not allowed for destination $i.\n";
            break;
        }
    }

    // Return the hook result and message
    return array($result, $message);
}

// Any switches passed to this script
$switches = (count($argv) > 1) ? $argv : array();

// Argument evaluation
if (in_array('--describe', $switches)) {
    echo json_encode(describe());
    exit;
} elseif (in_array('--add_api2', $switches)) {
    $input = get_passed_data();
    list($result, $message) = add_api2($input);
    echo "$result $message";
    exit;
} elseif (in_array('--add_uapi', $switches)) {
    $input = get_passed_data();
    list($result, $message) = add_uapi($input);
    echo "$result $message";
    exit;
} else {
    echo '0 vudubond2/disable_email_forward_hook.php needs a valid switch';
    exit(1);
}
