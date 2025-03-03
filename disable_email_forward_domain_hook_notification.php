#!/usr/local/cpanel/3rdparty/bin/php -q
<?php

/**
 * @version    1.0.0
 * @package    Disable Email Forwards
 * @author     Vudubond
 * @url
 * @copyright
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 */

// file - /usr/local/src/vudubond/disable_email_forward_hook.php

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
// mkdir /usr/local/src/vudubond
// cd /usr/local/src/vudubond;
// https://raw.githubusercontent.com/Vudubond/disable_email_forwarder_hook/disable_email_forward_hook.php
// copy file to folder
// chown root:root /usr/local/src/vudubond/disable_email_forward_hook.php;
// chmod 755 /usr/local/src/vudubond/disable_email_forward_hook.php;
// /usr/local/cpanel/bin/manage_hooks add script /usr/local/src/vudubond/disable_email_forward_hook.php
// create and populate the file /etc/forwarder_blocked_domains.txt
// touch /etc/forwarder_blocked_domains.txt

// Uninstall
// /usr/local/cpanel/bin/manage_hooks delete script /usr/local/src/vudubond/disable_email_forward_hook.php

// Embed hook attribute information
function describe()
{
    $api2_add_hook = array(
        'blocking' => 1,
        'category' => 'Cpanel',
        'event'    => 'Api2::Email::addforward',
        'stage'    => 'pre',
        'hook'     => '/usr/local/src/vudubond/disable_email_forward_hook.php --add_api2',
        'exectype' => 'script',
    );

    $uapi_add_hook = array(
        'blocking' => 1,
        'category' => 'Cpanel',
        'event'    => 'UAPI::Email::add_forwarder',
        'stage'    => 'pre',
        'hook'     => '/usr/local/src/vudubond/disable_email_forward_hook.php --add_uapi',
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

function send_notification_email($recipient, $subject, $body)
{
    $headers = 'From: root' . "\r\n" .
        'Reply-To: root' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();

    // Use mail() function to send the email
    mail($recipient, $subject, $body, $headers);
}

function add($input, $api_type)
{

    $api_function = 'uapi' === $api_type ? 'UAPI::Email::add_forwarder' : 'Api2::Email::addforward';
    $input_context = $input['context'];
    $input_args = $input['data']['args'];
    $email_from = $input_args['email'];
    $email_to = trim($input_args['fwdemail']);
    $domain = $input_args['domain'];
    $action_api = $input_context['event'];
    $action_forward = $input_args['fwdopt'];

    // $result = Set success boolean value
    // 1 — Success
    // 0 — Failure

    // $message = This string is a reason for $result.
    // To block the hook event on failure, you must set the blocking value to 1
    // in the describe() method and include BAILOUT in the failure message. If
    // the message does not include BAILOUT, the system will not block the event.

    // If forwarding destination does not end in the same domain as the account, deny it.
    if ($api_function === $action_api && 'fwd' === $action_forward) {
        // Is valid email?
        if (filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
            // We might echo the domain, so make sure it's clean first
            $sanitized_email_to = filter_var($email_to, FILTER_SANITIZE_EMAIL);

            // Split on @ and return last value of array (the domain)
            $email_to_parts = explode('@', $sanitized_email_to);

            // Block to domain as well          
            $email_to_domain = array_pop($email_to_parts);
          
            // Return a boolean if the domain matches
            //$result = ($domain === $email_to_domain) ? 1 : 0;
            //$message = 0 === $result ? "Forwarding to external domains not allowed, {$domain} is not equal to {$email_to_domain}." : '';
                // Populate list of bad domain names
        $baddomains = array_map('trim', file('/etc/forwarder_blocked_domains.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

// Check if full email OR domain is blocked
if (in_array($sanitized_email_to, $baddomains)) {
    $result = 0;
    $message = "Forwarding to {$sanitized_email_to} is not allowed.";
} elseif (in_array($email_to_domain, $baddomains)) {
    $result = 0;
    $message .= "Forwarding to {$sanitized_email_to} is blocked because forwarding to the domain ({$email_to_domain}) is not allowed.\n\nDetails at: https://www.clausweb.ro/politica-antispam.php";
} else {
     $result = 1;
     $message = '';
     // Send notification email when a forwarder is added
            $recipient = 'root'; // Change this to the email address where you want to receive notifications
            $hostname = gethostname();
            $subject = "Forwarder Added on $hostname";
            $body = "A forwarder has been added for domain '{$email_from}' @ '{$domain}'. Forwarded email address: '{$sanitized_email_to}'.";
            send_notification_email($recipient, $subject, $body);
}


        } else {
            // invalid email, fail
            $result = 0;
            $message = "Invalid email address.";
        }
    } else {
        // we're not filtering: fail, blackhole, pipe, system
        $result = 1;
        $message = "";
    }

    // On error, use:
    // throw new RuntimeException("BAILOUT $message");

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
    echo '0 vudubond/disable_email_forward_hook.php needs a valid switch';
    exit(1);
}
