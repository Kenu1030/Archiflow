<?php
http_response_code(410);
header('Content-Type: text/plain');
echo 'Google OAuth callback disabled.';
exit;
