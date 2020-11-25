# disable_email_forwarder_hook

If want to block whole domain change:

#153 if (in_array($sanitized_email_to, $baddomains)) {

to 

#153 if (in_array($email_to_domain, $baddomains)) {
