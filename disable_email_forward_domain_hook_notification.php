#!/usr/local/cpanel/3rdparty/bin/php -q
<?php

/**
 * @version    1.1.0
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
    $email_to_raw = $input_args['fwdemail'];
    $domain = $input_args['domain'];
    $action_api = $input_context['event'];
    $action_forward = $input_args['fwdopt'];

    $result = 1;
    $message = '';

    // Only act on email forwarders
    if ($api_function === $action_api && 'fwd' === $action_forward) {
        $baddomains = array_map('strtolower', array_map('trim', file('/etc/forwarder_blocked_domains.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));

        // Split on commas for multiple email addresses
        $forward_addresses = array_map('trim', explode(',', $email_to_raw));
        $blocked = [];

        foreach ($forward_addresses as $email_to) {
            if (!filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
                $result = 0;
                $message = "Invalid email address: {$email_to}.";
                break;
            }

            $sanitized_email_to = strtolower(filter_var($email_to, FILTER_SANITIZE_EMAIL));
            $email_to_parts = explode('@', $sanitized_email_to);
            $email_to_domain = array_pop($email_to_parts);

            if (in_array($sanitized_email_to, $baddomains)) {
                $result = 0;
                $message = "Forwarding to {$sanitized_email_to} is not allowed.";
                break;
            } elseif (in_array($email_to_domain, $baddomains)) {
                $result = 0;
                $message = "Forwarding to {$sanitized_email_to} is blocked because forwarding to the domain ({$email_to_domain}) is not allowed.\n\nDetails at: https://www.clausweb.ro/politica-antispam.php";
                break;
            }
        }

        if ($result === 1) {
            // Send notification once after all are validated
            $recipient = 'root'; // Change to desired notification email
            $hostname = gethostname();
            $subject = "Forwarder Added on $hostname";
            $forwarded_to = implode(', ', $forward_addresses);
            $body = "A forwarder has been added for '{$email_from}@{$domain}'.\nForwarded to: {$forwarded_to}";
            send_notification_email($recipient, $subject, $body);
        }

    } else {
        // Not a standard forward, allow
        $result = 1;
        $message = "";
    }

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
