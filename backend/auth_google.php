<?php
http_response_code(410);
header('Content-Type: text/plain');
echo 'Google sign-in is disabled on this application.';
exit;
